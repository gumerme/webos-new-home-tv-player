<?php
declare(strict_types=1);

namespace Casa;

/**
 * Informações do servidor que só o host enxerga (containers, atualizações, backups,
 * segurança, internet). O coletor (scripts/coletor-host.py, root, systemd a cada 60 s)
 * grava var/host.json; o painel só lê. Os containers não têm acesso ao Docker.
 */
final class Host
{
    private const ARQUIVO = __DIR__ . '/../var/host.json';

    public static function ler(): array
    {
        $txt = @file_get_contents(self::ARQUIVO);
        $d = $txt ? json_decode($txt, true) : null;
        if (!is_array($d)) {
            return ['disponivel' => false];
        }
        $d['disponivel'] = true;
        $d['idade_s'] = isset($d['gerado_em']) ? max(0, time() - (int) $d['gerado_em']) : null;
        return $d;
    }

    /** Latência e perda por bloco de tempo (para o gráfico de internet). */
    public static function serieRede(int $horas = 24, int $passoMin = 5): array
    {
        $st = Db::pdo()->prepare("
            select extract(epoch from date_bin(make_interval(mins => :p), coletado_em, timestamptz '2000-01-01'))::bigint as t,
                   round(avg(internet_ms)::numeric, 1) as internet,
                   round(avg(gateway_ms)::numeric, 1) as gateway,
                   round(max(internet_perda)::numeric, 0) as perda
              from metrica_rede
             where coletado_em > now() - make_interval(hours => :h)
             group by 1 order by 1");
        $st->execute(['p' => $passoMin, 'h' => $horas]);
        return $st->fetchAll();
    }

    /** Disponibilidade da internet: % de minutos com resposta nas últimas 24 h e 7 dias. */
    public static function disponibilidade(): array
    {
        $l = Db::pdo()->query("
            select 100.0 * count(*) filter (where coletado_em > now() - interval '24 hours' and internet_perda < 100)
                         / nullif(count(*) filter (where coletado_em > now() - interval '24 hours'), 0) as d24,
                   100.0 * count(*) filter (where internet_perda < 100) / nullif(count(*), 0) as d7,
                   count(*) filter (where coletado_em > now() - interval '24 hours') as amostras24
              from metrica_rede where coletado_em > now() - interval '7 days'")->fetch();
        return [
            'h24'        => $l['d24'] !== null ? round((float) $l['d24'], 2) : null,
            'd7'         => $l['d7'] !== null ? round((float) $l['d7'], 2) : null,
            'amostras24' => (int) $l['amostras24'],
        ];
    }
}
