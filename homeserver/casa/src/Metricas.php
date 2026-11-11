<?php
declare(strict_types=1);

namespace Casa;

/**
 * Métricas do homeserver.
 * Coleta (worker, root, rede do host): /proc, sensores e RAPL (energia medida pelo próprio processador).
 * Leitura (painel): última amostra, séries para gráficos e dados fixos da máquina.
 */
final class Metricas
{
    /** Onde o compose monta o powercap do host (o Docker esconde /sys/class/powercap). */
    private const RAPL = '/host/powercap';
    private const DISCO_RAIZ = '/app';          // /srv/casa fica no / do host
    private const DISCO_HOME = '/host/home';

    private ?array $cpuAnt = null;
    private array $energiaAnt = [];
    private ?array $redeAnt = null;

    // ------------------------------------------------------------- coleta

    /** Amostra atual. Na 1ª chamada só guarda a base (taxas precisam de duas leituras). */
    public function coletar(): ?array
    {
        $agora = microtime(true);
        $m = [];

        $cpu = self::lerCpu();
        $primeira = $this->cpuAnt === null;
        if (!$primeira) {
            $dt = $cpu['total'] - $this->cpuAnt['total'];
            $m['cpu_pct'] = $dt > 0 ? round(100 * (1 - ($cpu['ocioso'] - $this->cpuAnt['ocioso']) / $dt), 1) : null;
        }
        $this->cpuAnt = $cpu;

        $load = explode(' ', (string) @file_get_contents('/proc/loadavg'));
        $m['load1'] = isset($load[0]) ? (float) $load[0] : null;

        $mem = self::lerMeminfo();
        $m['ram_total'] = $mem['MemTotal'] ?? null;
        $m['ram_usada'] = isset($mem['MemTotal'], $mem['MemAvailable']) ? $mem['MemTotal'] - $mem['MemAvailable'] : null;
        $m['swap_total'] = $mem['SwapTotal'] ?? null;
        $m['swap_usada'] = isset($mem['SwapTotal'], $mem['SwapFree']) ? $mem['SwapTotal'] - $mem['SwapFree'] : null;

        [$m['disco_total'], $m['disco_usado']] = self::disco(self::DISCO_RAIZ);
        [$m['home_total'], $m['home_usado']] = self::disco(self::DISCO_HOME);

        $watts = $this->energia($agora);
        $m['energia_cpu_w'] = $watts['package-0'] ?? null;
        $m['energia_ram_w'] = $watts['dram'] ?? null;
        $m['energia_w'] = $m['energia_cpu_w'] === null ? null : round($m['energia_cpu_w'] + ($m['energia_ram_w'] ?? 0), 2);

        $m['temp_c'] = self::temperatura();

        $rede = self::lerRede();
        if ($rede && $this->redeAnt && $rede['iface'] === $this->redeAnt['iface']) {
            $dt = $agora - $this->redeAnt['t'];
            if ($dt > 0) {
                $m['rede_rx_bps'] = (int) max(0, ($rede['rx'] - $this->redeAnt['rx']) / $dt);
                $m['rede_tx_bps'] = (int) max(0, ($rede['tx'] - $this->redeAnt['tx']) / $dt);
            }
        }
        if ($rede) {
            $this->redeAnt = $rede + ['t' => $agora];
        }

        return $primeira ? null : $m;
    }

    public static function salvar(array $m): void
    {
        $cols = array_keys($m);
        $sql = 'insert into metrica (' . implode(', ', $cols) . ') values (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        Db::pdo()->prepare($sql)->execute(array_values($m));
    }

    public static function limpar(): void
    {
        $pdo = Db::pdo();
        $pdo->exec("delete from metrica where coletado_em < now() - interval '30 days'");
        $pdo->exec("delete from login_tentativa where criado_em < now() - interval '30 days'");
    }

    // ------------------------------------------------------------- leitura

    public static function atual(): ?array
    {
        $l = Db::pdo()->query('select *, extract(epoch from now() - coletado_em) as idade_s from metrica order by coletado_em desc limit 1')->fetch();
        if (!$l) {
            return null;
        }
        $l['energia_total_w'] = self::naTomada($l['energia_w'] !== null ? (float) $l['energia_w'] : null);
        return $l;
    }

    /** RAPL (processador + memória) -> estimativa do consumo da máquina na tomada. */
    public static function naTomada(?float $rapl): ?float
    {
        if ($rapl === null) {
            return null;
        }
        $c = Config::energiaCorrecao();
        return round(($rapl + $c['fixo_w']) / $c['eficiencia'], 2);
    }

    /** Médias em blocos (padrão: 24 h em blocos de 5 min). */
    public static function serie(int $horas = 24, int $passoMin = 5): array
    {
        $st = Db::pdo()->prepare("
            select extract(epoch from date_bin(make_interval(mins => :p), coletado_em, timestamptz '2000-01-01'))::bigint as t,
                   round(avg(cpu_pct)::numeric, 1) as cpu,
                   round(avg(ram_usada::float8 / nullif(ram_total, 0) * 100)::numeric, 1) as ram,
                   round(((avg(energia_w) + :fixo) / :ef)::numeric, 2) as energia,
                   round(avg(energia_w)::numeric, 2) as energia_rapl,
                   round(avg(temp_c)::numeric, 1) as temp,
                   round(avg(rede_rx_bps)) as rx,
                   round(avg(rede_tx_bps)) as tx
              from metrica
             where coletado_em > now() - make_interval(hours => :h)
             group by 1 order by 1");
        $c = Config::energiaCorrecao();
        $st->execute(['p' => $passoMin, 'h' => $horas, 'fixo' => $c['fixo_w'], 'ef' => $c['eficiencia']]);
        return $st->fetchAll();
    }

    /**
     * Consumo e custo estimados da máquina na tomada (RAPL corrigido pela classe, ver Config::energiaCorrecao).
     * kWh = soma das potências × 30 s de cada amostra; projeção = média das últimas 24 h × horas do mês.
     */
    public static function energiaResumo(): array
    {
        $c = Config::energiaCorrecao();
        $st = Db::pdo()->prepare("
            with m as (select coletado_em, energia_w as rapl, (energia_w + :fixo) / :ef as w from metrica where energia_w is not null)
            select avg(w)    filter (where coletado_em > now() - interval '24 hours') as media24,
                   avg(rapl) filter (where coletado_em > now() - interval '24 hours') as rapl24,
                   avg(w)    filter (where coletado_em > now() - interval '7 days')   as media7d,
                   sum(w)    filter (where coletado_em >= date_trunc('day', now()))   * 30 / 3600000.0 as kwh_hoje,
                   sum(w)    filter (where coletado_em >= date_trunc('month', now())) * 30 / 3600000.0 as kwh_mes_ate_agora,
                   extract(epoch from (date_trunc('month', now()) + interval '1 month') - date_trunc('month', now())) / 3600 as horas_mes
              from m");
        $st->execute(['fixo' => $c['fixo_w'], 'ef' => $c['eficiencia']]);
        $l = $st->fetch();
        $media = $l['media24'] !== null ? (float) $l['media24'] : null;
        $kwhProjecao = $media !== null ? $media * (float) $l['horas_mes'] / 1000 : null;
        $kwhHoje = $l['kwh_hoje'] !== null ? (float) $l['kwh_hoje'] : null;
        $kwhMes = $l['kwh_mes_ate_agora'] !== null ? (float) $l['kwh_mes_ate_agora'] : null;
        $tarifa = Config::tarifaKwh();
        $reais = fn(?float $kwh) => $kwh !== null && $tarifa !== null ? round($kwh * $tarifa, 2) : null;
        return [
            'correcao'           => $c,
            'rapl24_w'           => $l['rapl24'] !== null ? (float) $l['rapl24'] : null,
            'media24_w'          => $media,
            'media7d_w'          => $l['media7d'] !== null ? (float) $l['media7d'] : null,
            'kwh_hoje'           => $kwhHoje,
            'kwh_mes_ate_agora'  => $kwhMes,
            'kwh_mes'            => $kwhProjecao,
            'kwh_ano'            => $media !== null ? $media * 8760 / 1000 : null,
            'tarifa'             => $tarifa,
            'reais_hoje'         => $reais($kwhHoje),
            'reais_mes_ate_agora'=> $reais($kwhMes),
            'reais_mes'          => $reais($kwhProjecao),
            'reais_ano'          => $media !== null ? $reais($media * 8760 / 1000) : null,
        ];
    }

    /** Dados fixos da máquina (lidos pelo painel). */
    public static function info(): array
    {
        $os = @parse_ini_file('/host/os-release') ?: [];
        $cpu = '';
        $nucleos = 0;
        foreach (@file('/proc/cpuinfo') ?: [] as $linha) {
            if (str_starts_with($linha, 'model name') && $cpu === '') {
                $cpu = trim(explode(':', $linha, 2)[1]);
            }
            if (str_starts_with($linha, 'processor')) {
                $nucleos++;
            }
        }
        $uptime = (float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0];
        return [
            'hostname' => gethostname(),
            'sistema'  => $os['PRETTY_NAME'] ?? 'Linux',
            'kernel'   => php_uname('r'),
            'cpu'      => preg_replace('/\s+/', ' ', $cpu),
            'nucleos'  => $nucleos,
            'uptime_s' => (int) $uptime,
            'ip'       => self::ipPrincipal(),
        ];
    }

    // ------------------------------------------------------------- helpers

    private static function lerCpu(): array
    {
        $l = preg_split('/\s+/', trim(strtok((string) file_get_contents('/proc/stat'), "\n")));
        $v = array_map('intval', array_slice($l, 1, 8));   // user nice system idle iowait irq softirq steal
        return ['total' => array_sum($v), 'ocioso' => $v[3] + $v[4]];
    }

    private static function lerMeminfo(): array
    {
        $r = [];
        foreach (@file('/proc/meminfo') ?: [] as $linha) {
            if (preg_match('/^(\w+):\s+(\d+) kB/', $linha, $x)) {
                $r[$x[1]] = (int) $x[2] * 1024;
            }
        }
        return $r;
    }

    private static function disco(string $caminho): array
    {
        $total = @disk_total_space($caminho);
        $livre = @disk_free_space($caminho);
        if ($total === false || $livre === false || $total <= 0) {
            return [null, null];
        }
        return [(int) $total, (int) ($total - $livre)];
    }

    /** Watts por domínio RAPL (package-0, core, uncore, dram) desde a última leitura. */
    private function energia(float $agora): array
    {
        $watts = [];
        $dirs = array_merge(
            glob(self::RAPL . '/intel-rapl/intel-rapl:*') ?: [],
            glob(self::RAPL . '/intel-rapl/intel-rapl:*/intel-rapl:*:*') ?: []
        );
        foreach ($dirs as $dir) {
            $nome = trim((string) @file_get_contents("$dir/name"));
            $uj = @file_get_contents("$dir/energy_uj");
            if ($nome === '' || $uj === false) {
                continue;
            }
            $uj = (int) $uj;
            $ant = $this->energiaAnt[$dir] ?? null;
            if ($ant) {
                $delta = $uj - $ant['uj'];
                if ($delta < 0) {   // contador deu a volta
                    $delta += (int) @file_get_contents("$dir/max_energy_range_uj");
                }
                $dt = $agora - $ant['t'];
                if ($dt > 0 && $delta >= 0) {
                    $watts[$nome] = round($delta / 1e6 / $dt, 2);
                }
            }
            $this->energiaAnt[$dir] = ['uj' => $uj, 't' => $agora];
        }
        return $watts;
    }

    private static function temperatura(): ?float
    {
        foreach (glob('/sys/class/thermal/thermal_zone*') ?: [] as $z) {
            if (trim((string) @file_get_contents("$z/type")) === 'x86_pkg_temp') {
                return round((int) @file_get_contents("$z/temp") / 1000, 1);
            }
        }
        foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $h) {
            if (trim((string) @file_get_contents("$h/name")) === 'coretemp') {
                return round((int) @file_get_contents("$h/temp1_input") / 1000, 1);
            }
        }
        return null;
    }

    /** Bytes da interface da rota padrão. */
    private static function lerRede(): ?array
    {
        $iface = self::ifacePadrao();
        if (!$iface) {
            return null;
        }
        foreach (@file('/proc/net/dev') ?: [] as $linha) {
            if (preg_match('/^\s*' . preg_quote($iface, '/') . ':\s*(.*)$/', $linha, $x)) {
                $c = preg_split('/\s+/', trim($x[1]));
                return ['iface' => $iface, 'rx' => (int) $c[0], 'tx' => (int) $c[8]];
            }
        }
        return null;
    }

    private static function ifacePadrao(): ?string
    {
        foreach (array_slice(@file('/proc/net/route') ?: [], 1) as $linha) {
            $c = preg_split('/\s+/', trim($linha));
            if (($c[1] ?? '') === '00000000') {
                return $c[0];
            }
        }
        return null;
    }

    private static function ipPrincipal(): ?string
    {
        $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$s) {
            return null;
        }
        @socket_connect($s, '192.0.2.1', 9);   // não envia nada; só escolhe a rota
        @socket_getsockname($s, $ip);
        socket_close($s);
        return $ip ?: null;
    }
}
