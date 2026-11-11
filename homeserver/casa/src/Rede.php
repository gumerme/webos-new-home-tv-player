<?php
declare(strict_types=1);

namespace Casa;

/**
 * Aparelhos da rede de casa (tabela rede_dispositivo, alimentada pelo coletor do host a cada 5 min)
 * e sincronização dos IPs dos aparelhos cadastrados.
 */
final class Rede
{
    /** Considera "visto agora" quem apareceu nas últimas varreduras. */
    private const FRESCO = "interval '15 minutes'";

    public static function listar(string $filtro = 'online'): array
    {
        $where = match ($filtro) {
            'todos'         => 'true',
            'desconhecidos' => 'not r.conhecido',
            default         => 'r.online',
        };
        $sql = "
            select r.*, extract(epoch from r.ultimo_visto)::bigint as ultimo_ts,
                   extract(epoch from r.primeiro_visto)::bigint as primeiro_ts,
                   (r.primeiro_visto > now() - interval '24 hours') as novo,
                   d.slug as disp_slug, d.nome as disp_nome
              from rede_dispositivo r
              left join dispositivo d on lower(d.config->>'mac') = r.mac
             where $where
             order by r.online desc, string_to_array(r.ip, '.')::int[] nulls last";
        return array_map(self::hidratar(...), Db::pdo()->query($sql)->fetchAll());
    }

    public static function porMac(string $mac): ?array
    {
        $st = Db::pdo()->prepare('select r.*, null as disp_slug, null as disp_nome, extract(epoch from r.ultimo_visto)::bigint as ultimo_ts,
                                         extract(epoch from r.primeiro_visto)::bigint as primeiro_ts, false as novo
                                    from rede_dispositivo r where r.mac = ?');
        $st->execute([strtolower($mac)]);
        $l = $st->fetch();
        return $l ? self::hidratar($l) : null;
    }

    public static function resumo(): array
    {
        $l = Db::pdo()->query("
            select count(*) filter (where online) as online,
                   count(*) as total,
                   count(*) filter (where not conhecido) as desconhecidos,
                   count(*) filter (where primeiro_visto > now() - interval '24 hours' and not conhecido) as novos24,
                   extract(epoch from max(ultimo_visto))::bigint as ultima_varredura
              from rede_dispositivo")->fetch();
        return array_map(fn($v) => $v === null ? null : (int) $v, $l);
    }

    public static function salvar(string $mac, ?string $apelido, bool $conhecido): void
    {
        $apelido = $apelido !== null ? trim($apelido) : null;
        if ($apelido !== null && mb_strlen($apelido) > 60) {
            throw new \InvalidArgumentException('Apelido com no máximo 60 caracteres.');
        }
        Db::pdo()->prepare('update rede_dispositivo set apelido = ?, conhecido = ? where mac = ?')
            ->execute([$apelido ?: null, $conhecido ? 'true' : 'false', strtolower($mac)]);
    }

    /** Desconhecidos que apareceram nas últimas 24 h (para os pontos de atenção). */
    public static function novosDesconhecidos(): array
    {
        try {
            return Db::pdo()->query("select mac, ip, coalesce(upnp_nome, nome_dns, fabricante, 'sem nome') as nome
                                       from rede_dispositivo
                                      where not conhecido and primeiro_visto > now() - interval '24 hours'
                                      order by primeiro_visto desc")->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Mantém o IP dos aparelhos cadastrados igual ao que a rede mostra:
     *  - com MAC: se o MAC aparece em outro IP, troca o IP;
     *  - sem MAC: se um único aparelho da rede anuncia o mesmo modelo (UPnP), grava IP e MAC.
     * @return list<string> mudanças feitas (para o log do worker)
     */
    public static function sincronizarIps(): array
    {
        $pdo = Db::pdo();
        $mudou = [];
        $disp = $pdo->query("select id, slug, nome, config->>'ip' as ip, lower(config->>'mac') as mac, config->>'modelo' as modelo
                               from dispositivo where ativo")->fetchAll();
        foreach ($disp as $d) {
            if (!empty($d['mac'])) {
                $st = $pdo->prepare('select ip from rede_dispositivo where mac = ? and online and ultimo_visto > now() - ' . self::FRESCO);
                $st->execute([$d['mac']]);
                $ip = $st->fetchColumn();
                if ($ip && $ip !== $d['ip']) {
                    Dispositivos::mesclarConfig((int) $d['id'], ['ip' => $ip]);
                    Eventos::registrar('aparelho', 'info', "{$d['nome']}: IP mudou para $ip", 'antes: ' . ($d['ip'] ?: 'sem IP') . ' · MAC ' . $d['mac'], 'worker');
                    $mudou[] = "{$d['slug']} {$d['ip']} -> $ip";
                }
            } elseif (!empty($d['modelo'])) {
                $st = $pdo->prepare('select mac, ip from rede_dispositivo
                                      where online and ultimo_visto > now() - ' . self::FRESCO . '
                                        and upper(upnp_modelo) like ?');
                $st->execute(['%' . strtoupper($d['modelo']) . '%']);
                $achados = $st->fetchAll();
                if (count($achados) === 1) {
                    [$mac, $ip] = [$achados[0]['mac'], $achados[0]['ip']];
                    Dispositivos::mesclarConfig((int) $d['id'], ['ip' => $ip, 'mac' => $mac]);
                    Eventos::registrar('aparelho', 'ok', "{$d['nome']} encontrada na rede: $ip", "pelo modelo {$d['modelo']} · MAC $mac", 'worker');
                    $mudou[] = "{$d['slug']} encontrado em $ip ($mac)";
                }
            }
        }
        return $mudou;
    }

    private static function hidratar(array $l): array
    {
        foreach (['conhecido', 'online', 'novo', 'mac_privado'] as $b) {
            $l[$b] = filter_var($l[$b] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        $l['extras'] = json_decode((string) ($l['extras'] ?? '{}'), true) ?: [];
        $l['servicos'] = isset($l['mdns_servicos']) && $l['mdns_servicos'] !== null
            ? array_values(array_filter(explode(',', trim((string) $l['mdns_servicos'], '{}')), fn($s) => $s !== '')) : [];
        $l['modelo_exibir'] = $l['upnp_modelo'] ?: ($l['modelo'] ?? null) ?: self::modeloDoTitulo($l['http_titulo'] ?? null);
        $l['nome'] = $l['apelido'] ?: ($l['disp_nome'] ?? null) ?: $l['upnp_nome'] ?: ($l['mdns_nome'] ?? null)
            ?: self::nomeDns($l['nome_dns']) ?: ($l['modelo_exibir'] ?: ($l['fabricante'] ?: 'Aparelho sem nome'));
        $l['tipo'] = self::tipo($l);
        return $l;
    }

    /** Título de página que é só "Login"/"Home" não diz o modelo. */
    private static function modeloDoTitulo(?string $t): ?string
    {
        if (!$t || preg_match('/^(login|log in|home|index|welcome|redirect|loading|web ?ui|router|\s*)$/i', trim($t))) {
            return null;
        }
        return mb_substr($t, 0, 60);
    }

    /** Palpite de tipo pelo que o aparelho anunciou. */
    private static function tipo(array $l): string
    {
        $s = $l['servicos'];
        $t = strtolower(implode(' ', array_filter([$l['upnp_servidor'], $l['upnp_modelo'], $l['modelo'] ?? null, $l['fabricante'],
            $l['nome_dns'], $l['http_titulo'] ?? null, $l['upnp_nome'], $l['mdns_nome'] ?? null])));
        return match (true) {
            str_contains($t, 'homeserver')                                                   => 'Servidor',
            str_contains($t, 'webos') || in_array('androidtvremote2', $s) || str_contains($t, ' tv') => 'TV',
            in_array('googlecast', $s)                                                       => 'Chromecast / Google TV',
            in_array('ipp', $s) || in_array('printer', $s) || in_array('pdl-datastream', $s) => 'Impressora',
            str_contains($t, 'iphone') || str_contains($t, 'ipad') || in_array('apple-mobdev2', $s) => 'iPhone / iPad',
            str_contains($t, 'macbook') || str_contains($t, 'imac') || str_contains($t, 'macmini') => 'Mac',
            in_array('airplay', $s) || in_array('raop', $s)                                  => 'AirPlay',
            in_array('hap', $s) || in_array('matter', $s) || in_array('matterc', $s)         => 'Casa inteligente',
            preg_match('/router|mercusys|zte|parks|arcadyan|tp-link|huawei|intelbras|deco|mesh/', $t) === 1 => 'Roteador / Wi-Fi',
            str_contains($t, 'galaxy') || str_contains($t, 'android') || $l['mac_privado']  => 'Celular / tablet',
            str_contains($t, 'apple')                                                        => 'Apple (iPhone, iPad ou Mac)',
            str_contains($t, 'camera') || str_contains($t, 'cam') => 'Câmera / campainha',
            in_array('workstation', $s) || in_array('smb', $s)                               => 'Computador',
            default                                                                          => 'Aparelho',
        };
    }

    /** "lgwebostv.lan" -> "lgwebostv"; ignora nomes que são só o IP. */
    private static function nomeDns(?string $n): ?string
    {
        if (!$n || preg_match('/^\d+\.\d+\.\d+\.\d+$/', $n)) {
            return null;
        }
        return explode('.', $n)[0];
    }
}
