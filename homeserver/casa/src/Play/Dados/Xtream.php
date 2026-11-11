<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;
use RuntimeException;

/**
 * Painel IPTV (Xtream Codes): conta ativa, chamadas ao player_api.php, links de vídeo e episódios.
 * O painel recusa downloads grandes em paralelo: o worker sempre pede uma lista de cada vez.
 * Usuário e senha nunca saem em log (Http::segredo).
 */
final class Xtream
{
    /** Cache de episódios (get_series_info). */
    private const SERIE_CACHE_S = 6 * 3600;

    private static array $contas = [];

    public static function contaAtiva(): ?array
    {
        $c = Db::pdo()->query('select * from play.conta where ativa limit 1')->fetch();
        if (!$c) {
            return null;
        }
        return self::preparar($c);
    }

    public static function conta(int $id): ?array
    {
        if (isset(self::$contas[$id])) {
            return self::$contas[$id];
        }
        $st = Db::pdo()->prepare('select * from play.conta where id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        return $c ? self::preparar($c) : null;
    }

    private static function preparar(array $c): array
    {
        $c['id'] = (int) $c['id'];
        $c['servidor'] = rtrim((string) $c['servidor'], '/');
        Http::segredo($c['usuario']);
        Http::segredo($c['senha']);
        return self::$contas[$c['id']] = $c;
    }

    public static function url(array $conta, string $acao, array $params = []): string
    {
        $q = ['username' => $conta['usuario'], 'password' => $conta['senha']];
        if ($acao !== '') {
            $q['action'] = $acao;
        }
        return $conta['servidor'] . '/player_api.php?' . http_build_query($q + $params);
    }

    /** Chamada ao player_api.php; listas grandes (44 mil filmes) pedem timeout longo. */
    public static function api(array $conta, string $acao, array $params = [], int $timeout = 30): mixed
    {
        $j = Http::json(self::url($conta, $acao, $params), [], $timeout);
        if ($acao !== '' && is_array($j) && isset($j['user_info']) && !isset($j[0])) {
            // o painel devolve as informações da conta quando a ação não existe ou a conta caiu
            throw new RuntimeException("painel não atendeu a ação $acao (status da conta: " . ($j['user_info']['status'] ?? '?') . ')');
        }
        return $j;
    }

    public static function linkVideo(array $conta, string $tipo, int $id, string $ext): string
    {
        $pasta = $tipo === 's' ? 'series' : 'movie';
        $ext = preg_match('/^[a-z0-9]{2,5}$/i', $ext) ? $ext : 'mp4';
        return $conta['servidor'] . "/$pasta/" . rawurlencode($conta['usuario']) . '/' . rawurlencode($conta['senha']) . "/$id.$ext";
    }

    public static function linkCanal(array $conta, int $canalId): string
    {
        return $conta['servidor'] . '/live/' . rawurlencode($conta['usuario']) . '/' . rawurlencode($conta['senha']) . "/$canalId.m3u8";
    }

    /**
     * Informações da conta (player_api.php sem ação): status, vencimento, conexões.
     * @return array{status:?string, vence_em:?int, ms:int, info:array}
     */
    public static function informacoes(array $conta): array
    {
        $ini = microtime(true);
        $j = Http::json(self::url($conta, ''), [], 20);
        $ms = (int) round((microtime(true) - $ini) * 1000);
        $u = is_array($j) ? ($j['user_info'] ?? null) : null;
        if (!is_array($u)) {
            throw new RuntimeException('painel não devolveu as informações da conta');
        }
        $s = is_array($j['server_info'] ?? null) ? $j['server_info'] : [];
        unset($u['username'], $u['password']);
        $info = ['usuario' => array_intersect_key($u, array_flip(['status', 'exp_date', 'is_trial', 'active_cons', 'created_at', 'max_connections', 'allowed_output_formats', 'auth', 'message'])),
                 'servidor' => array_intersect_key($s, array_flip(['url', 'port', 'https_port', 'server_protocol', 'timezone', 'timestamp_now', 'time_now']))];
        $exp = (int) ($u['exp_date'] ?? 0);
        return ['status' => isset($u['status']) ? (string) $u['status'] : null, 'vence_em' => $exp > 0 ? $exp : null, 'ms' => $ms, 'info' => $info];
    }

    /** Tarefa "conta" (30 min): status, vencimento e latência da conta ativa. */
    public static function tarefaConta(): array
    {
        $c = self::contaAtiva();
        if (!$c) {
            return ['msg' => 'sem conta ativa (cadastre em Player › Listas ou bin/play.php conta-adicionar)'];
        }
        $i = self::informacoes($c);
        Db::pdo()->prepare('update play.conta set status = ?, vence_em = to_timestamp(?), info = cast(? as jsonb),
                                   latencia_ms = ?, ultima_resposta_em = now() where id = ?')
            ->execute([$i['status'], $i['vence_em'], json_encode($i['info'], JSON_UNESCAPED_UNICODE), $i['ms'], $c['id']]);
        $vence = $i['vence_em'] ? date('d/m/Y', $i['vence_em']) : 'sem data';
        return ['msg' => sprintf('%s: %s, vence %s, %d ms', $c['nome'], $i['status'] ?? '?', $vence, $i['ms'])];
    }

    /**
     * Episódios de uma série do painel em play.episodio + play.serie_info (cache de 6 h).
     * Episódio que sumiu do painel sai da tabela.
     */
    public static function serieInfo(int $contaId, int $serieId, bool $forcar = false): void
    {
        $pdo = Db::pdo();
        if (!$forcar) {
            $st = $pdo->prepare('select atualizado_em > now() - make_interval(secs => ?) from play.serie_info where conta_id = ? and serie_id = ?');
            $st->execute([self::SERIE_CACHE_S, $contaId, $serieId]);
            if (Banco::bool($st->fetchColumn())) {
                return;
            }
        }
        $conta = self::conta($contaId) ?? throw new RuntimeException("conta $contaId não existe");
        $d = self::api($conta, 'get_series_info', ['series_id' => $serieId], 30);
        $eps = self::lerEpisodios(is_array($d) ? $d : []);
        $pdo->beginTransaction();
        try {
            if ($eps) {
                $pdo->prepare("
                    insert into play.episodio (conta_id, serie_id, ep_id, temporada, numero, nome, sinopse, ext, duracao_s, still, adicionado_em)
                    select distinct on (x.ep_id) ?, ?, x.ep_id, x.temporada, x.numero, x.nome, x.sinopse, x.ext, x.duracao_s, x.still, to_timestamp(x.adicionado)
                      from jsonb_to_recordset(cast(? as jsonb)) as x(ep_id int, temporada int, numero int, nome text, sinopse text,
                                                                     ext text, duracao_s int, still text, adicionado bigint)
                    on conflict (conta_id, serie_id, ep_id) do update set
                        temporada = excluded.temporada, numero = excluded.numero, nome = excluded.nome, sinopse = excluded.sinopse,
                        ext = excluded.ext, duracao_s = excluded.duracao_s, still = excluded.still,
                        adicionado_em = coalesce(excluded.adicionado_em, play.episodio.adicionado_em)")
                    ->execute([$contaId, $serieId, json_encode($eps, JSON_UNESCAPED_UNICODE)]);
                $ids = '{' . implode(',', array_column($eps, 'ep_id')) . '}';
                $pdo->prepare('delete from play.episodio where conta_id = ? and serie_id = ? and not (ep_id = any(cast(? as int[])))')
                    ->execute([$contaId, $serieId, $ids]);
            }
            $pdo->prepare('insert into play.serie_info (conta_id, serie_id, atualizado_em) values (?, ?, now())
                           on conflict (conta_id, serie_id) do update set atualizado_em = now()')->execute([$contaId, $serieId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** get_series_info → episódios no formato da tabela (função pura, testada). */
    public static function lerEpisodios(array $d): array
    {
        $saida = [];
        $porTemporada = $d['episodes'] ?? [];
        if (!is_array($porTemporada)) {
            return [];
        }
        foreach ($porTemporada as $temp => $lista) {
            if (!is_array($lista)) {
                continue;
            }
            foreach ($lista as $e) {
                if (!is_array($e) || !isset($e['id'])) {
                    continue;
                }
                $info = is_array($e['info'] ?? null) ? $e['info'] : [];
                $dur = (int) ($info['duration_secs'] ?? 0);
                if (!$dur && preg_match('/^(\d+):(\d{2}):(\d{2})$/', (string) ($info['duration'] ?? ''), $m)) {
                    $dur = $m[1] * 3600 + $m[2] * 60 + $m[3];
                }
                $saida[] = [
                    'ep_id'      => (int) $e['id'],
                    'temporada'  => (int) ($e['season'] ?? $temp),
                    'numero'     => (int) ($e['episode_num'] ?? 0),
                    'nome'       => isset($e['title']) ? mb_substr((string) $e['title'], 0, 300) : null,
                    'sinopse'    => isset($info['plot']) && $info['plot'] !== '' ? (string) $info['plot'] : null,
                    'ext'        => isset($e['container_extension']) ? (string) $e['container_extension'] : null,
                    'duracao_s'  => $dur ?: null,
                    'still'      => is_string($info['movie_image'] ?? null) && $info['movie_image'] !== '' ? $info['movie_image'] : null,
                    'adicionado' => (int) ($e['added'] ?? 0) ?: null,
                    'altura'     => (int) ($info['video']['height'] ?? 0) ?: null,
                ];
            }
        }
        return $saida;
    }
}
