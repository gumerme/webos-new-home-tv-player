<?php
declare(strict_types=1);

namespace Casa\Play\Api;

/**
 * POST importar {perfil, dados:{...localStorage da v10}} — primeira abertura da 2.0 em cada TV.
 * Os ids da v10 são do painel (stream_id de filme, series_id de série, id de episódio): viram play.titulo
 * por play.versao (e pelo tmdb_map como reserva). Importar duas vezes (ou das duas TVs) não duplica nada;
 * o que já existe na 2.0 e é mais novo não é sobrescrito.
 */
final class Importar
{
    /**
     * Importação da v10 DESLIGADA (decisão do usuário em 08/11/2026: algoritmo recomeçou limpo e nada do app
     * antigo volta, nem favoritos). Responde 200 sem gravar; a TV marca como importado e não tenta de novo.
     * Para religar: trocar para false.
     */
    public const DESLIGADA = true;

    public static function rota(Pedido $p): array
    {
        if (self::DESLIGADA) {
            return ['importados' => null, 'bloqueado' => true, 'perfil' => $p->perfil()];
        }
        $perfil = $p->perfil();
        $dados = $p->corpo['dados'] ?? null;
        if (!is_array($dados)) {
            throw new Erro('informe dados (localStorage da v10)', 422);
        }
        $n = self::interpretar($dados);
        return ['importados' => self::gravar($perfil, $n), 'perfil' => $perfil];
    }

    // ------------------------------------------------------ leitura (pura)

    /** Valor do localStorage: vem como texto JSON (cópia crua) ou já decodificado. */
    private static function ler(array $dados, string $chave): mixed
    {
        $v = $dados[$chave] ?? null;
        if (is_string($v)) {
            $d = json_decode($v, true);
            return json_last_error() === JSON_ERROR_NONE ? $d : $v;
        }
        return $v;
    }

    private static function id(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        return is_string($v) && preg_match('/^\d{1,12}$/', $v) && (int) $v > 0 ? (int) $v : (is_float($v) && $v > 0 ? (int) $v : null);
    }

    private static function ms(mixed $v): ?int
    {
        return is_numeric($v) && (float) $v > 1_000_000_000_000 ? (int) $v : null;   // milissegundos (depois de 2001)
    }

    /** Separa "m123" / "s45" / "e789" em [tipo, id]. */
    private static function chave(string $k): ?array
    {
        return preg_match('/^([mse])(\d{1,12})$/', $k, $m) ? [$m[1], (int) $m[2]] : null;
    }

    /**
     * Normaliza o localStorage da v10 (sem banco). Formatos:
     *  play_history  [{type, id, tmdb, title, g, f, eps, ep, best, se:{s,e}, t}]
     *  play_progress {"m<id>"|"e<epId>": {p, d, t, kind, id, seriesId, season, episode, ...}, "s<id>last": {...}}
     *  my_list [{type, id, t}] · play_ratings {"m<id>": {v, t}} · play_intros {"<seriesId>": {s, e, t}}
     *  play_versions {"m<id>": "leg"} · live_favs ["<canal>"] · subs_pref "pt-br"|"off" · subs_size "p|m|g"
     *  subs_delay {"m<id>"|"e<epId>": {f, d, t}} · continue_hidden {"m<id>"|"s<id>"|"pm<id>"|"pe<epId>": t}
     *  tmdb_map {"m<id>": tmdb}
     */
    public static function interpretar(array $dados): array
    {
        $n = ['historico' => [], 'progresso' => [], 'lista' => [], 'notas' => [], 'aberturas' => [], 'versoes' => [],
              'favoritos' => [], 'prefs' => [], 'atrasos' => [], 'ocultos' => [], 'tmdb' => []];

        foreach ((array) self::ler($dados, 'tmdb_map') as $k => $tmdb) {
            if (self::chave((string) $k) && self::id($tmdb)) {
                $n['tmdb'][(string) $k] = self::id($tmdb);
            }
        }

        foreach ((array) self::ler($dados, 'play_history') as $h) {
            if (!is_array($h) || !in_array($h['type'] ?? null, ['m', 's'], true) || !($id = self::id($h['id'] ?? null))) {
                continue;
            }
            $se = is_array($h['se'] ?? null) ? $h['se'] : [];
            $n['historico'][] = [
                'tipo'      => $h['type'],
                'painel'    => $id,
                'tmdb'      => self::id($h['tmdb'] ?? null) ?? ($n['tmdb'][$h['type'] . $id] ?? null),
                'quando'    => self::ms($h['t'] ?? null) ?? 0,
                'temporada' => $h['type'] === 's' ? self::id($se['s'] ?? null) : null,
                'episodio'  => $h['type'] === 's' ? self::id($se['e'] ?? null) : null,
                'ep'        => $h['type'] === 's' ? self::id($h['ep'] ?? null) : null,
                'fracao'    => max(0.0, min(1.0, (float) ($h['f'] ?? 0))),
                'terminado' => (float) ($h['f'] ?? 0) >= 0.85,
            ];
        }

        foreach ((array) self::ler($dados, 'play_progress') as $k => $x) {
            $c = self::chave((string) $k);
            if (!$c || !is_array($x) || $c[0] === 's') {
                continue;   // "s<id>last" é só um marcador
            }
            $pos = max(0, (int) ($x['p'] ?? 0));
            if ($pos < 30) {
                continue;
            }
            $n['progresso'][] = [
                'tipo'      => $c[0],                      // m filme | e episódio
                'painel'    => $c[1],                      // stream_id ou id do episódio
                'serie'     => $c[0] === 'e' ? self::id($x['seriesId'] ?? null) : null,
                'temporada' => $c[0] === 'e' ? self::id($x['season'] ?? null) : null,
                'episodio'  => $c[0] === 'e' ? self::id($x['episode'] ?? null) : null,
                'pos'       => $pos,
                'dur'       => max(0, (int) ($x['d'] ?? 0)),
                'quando'    => self::ms($x['t'] ?? null) ?? 0,
            ];
        }

        foreach ((array) self::ler($dados, 'my_list') as $x) {
            if (is_array($x) && in_array($x['type'] ?? null, ['m', 's'], true) && ($id = self::id($x['id'] ?? null))) {
                $n['lista'][] = ['tipo' => $x['type'], 'painel' => $id, 'quando' => self::ms($x['t'] ?? null) ?? 0];
            }
        }

        foreach ((array) self::ler($dados, 'play_ratings') as $k => $x) {
            $c = self::chave((string) $k);
            $v = is_array($x) ? (int) ($x['v'] ?? 0) : 0;
            if ($c && $c[0] !== 'e' && in_array($v, [1, -1], true)) {
                $n['notas'][] = ['tipo' => $c[0], 'painel' => $c[1], 'valor' => $v, 'quando' => self::ms($x['t'] ?? null) ?? 0];
            }
        }

        foreach ((array) self::ler($dados, 'play_intros') as $k => $x) {
            if (($id = self::id((string) $k)) && is_array($x) && is_numeric($x['s'] ?? null) && is_numeric($x['e'] ?? null)) {
                $n['aberturas'][] = ['serie' => $id, 'inicio' => (int) $x['s'], 'fim' => (int) $x['e']];
            }
        }

        foreach ((array) self::ler($dados, 'play_versions') as $k => $v) {
            $c = self::chave((string) $k);
            if ($c && $c[0] !== 'e' && in_array($v, ['dub', 'leg'], true)) {
                $n['versoes'][] = ['tipo' => $c[0], 'painel' => $c[1], 'idioma' => $v];
            }
        }

        foreach ((array) self::ler($dados, 'live_favs') as $x) {
            if ($id = self::id(is_int($x) ? $x : (string) $x)) {
                $n['favoritos'][] = $id;
            }
        }
        $n['favoritos'] = array_values(array_unique($n['favoritos']));

        $pref = self::ler($dados, 'subs_pref');
        if (in_array($pref, ['pt-br', 'off'], true)) {
            $n['prefs']['legenda_auto'] = $pref === 'pt-br';
        }
        $tam = self::ler($dados, 'subs_size');
        if (in_array($tam, ['p', 'm', 'g'], true)) {
            $n['prefs']['legenda_tamanho'] = $tam;
        }
        foreach ((array) self::ler($dados, 'subs_delay') as $k => $x) {
            $c = self::chave((string) $k);
            if ($c && $c[0] !== 's' && is_array($x) && is_numeric($x['d'] ?? null) && (float) $x['d'] != 0.0
                && is_string($x['f'] ?? null) && preg_match('/^[\w.\- ]{1,120}$/u', $x['f'])) {
                $n['atrasos'][] = ['tipo' => $c[0], 'painel' => $c[1], 'arquivo' => $x['f'], 'atraso' => round((float) $x['d'], 1)];
            }
        }

        foreach ((array) self::ler($dados, 'continue_hidden') as $k => $t) {
            $k = (string) $k;
            $c = self::chave(str_starts_with($k, 'p') ? substr($k, 1) : $k);
            if ($c && ($ms = self::ms($t))) {
                $n['ocultos'][] = ['tipo' => $c[0], 'painel' => $c[1], 'quando' => $ms];
            }
        }
        return $n;
    }

    // ---------------------------------------------------------- gravação

    /** Ids do painel -> play.titulo (lista ativa primeiro; tmdb_map como reserva). */
    private static function mapear(array $n): array
    {
        $ids = ['m' => [], 's' => []];
        $tmdb = [];
        foreach (['historico', 'lista', 'notas', 'versoes'] as $sec) {
            foreach ($n[$sec] as $x) {
                $ids[$x['tipo']][] = $x['painel'];
            }
        }
        foreach ($n['progresso'] as $x) {
            if ($x['tipo'] === 'm') {
                $ids['m'][] = $x['painel'];
            } elseif ($x['serie']) {
                $ids['s'][] = $x['serie'];
            }
        }
        foreach ($n['aberturas'] as $x) {
            $ids['s'][] = $x['serie'];
        }
        foreach (array_merge($n['atrasos'], $n['ocultos']) as $x) {
            if ($x['tipo'] !== 'e') {
                $ids[$x['tipo']][] = $x['painel'];
            }
        }

        // Episódios sem série (progresso antigo) e atrasos/ocultos por episódio: pela tabela de episódios.
        $eps = [];
        foreach ($n['progresso'] as $x) {
            if ($x['tipo'] === 'e') {
                $eps[] = $x['painel'];
            }
        }
        foreach (array_merge($n['atrasos'], $n['ocultos']) as $x) {
            if ($x['tipo'] === 'e') {
                $eps[] = $x['painel'];
            }
        }
        $episodio = [];
        if ($eps) {
            foreach (Base::linhas('select distinct on (ep_id) ep_id, serie_id, temporada, numero from play.episodio
                                    where ep_id = any(cast(? as int[])) order by ep_id, (conta_id = ?) desc', [Base::arr($eps), Base::contaId()]) as $e) {
                $episodio[(int) $e['ep_id']] = ['serie' => (int) $e['serie_id'], 'temporada' => (int) $e['temporada'], 'numero' => (int) $e['numero']];
                $ids['s'][] = (int) $e['serie_id'];
            }
        }

        $titulo = [];
        foreach (['m', 's'] as $tipo) {
            if (!$ids[$tipo]) {
                continue;
            }
            foreach (Base::linhas('select distinct on (painel_id) painel_id, titulo_id from play.versao
                                    where tipo = ? and painel_id = any(cast(? as int[])) and titulo_id is not null
                                    order by painel_id, (conta_id = ?) desc', [$tipo, Base::arr($ids[$tipo]), Base::contaId()]) as $v) {
                $titulo[$tipo . (int) $v['painel_id']] = (int) $v['titulo_id'];
            }
        }
        // Reserva: TMDB (tmdb_map e o tmdb do histórico).
        foreach ($n['historico'] as $h) {
            if ($h['tmdb'] && !isset($n['tmdb'][$h['tipo'] . $h['painel']])) {
                $n['tmdb'][$h['tipo'] . $h['painel']] = $h['tmdb'];
            }
        }
        foreach ($n['tmdb'] as $k => $t) {
            if (!isset($titulo[$k]) && $k[0] !== 'e') {
                $tmdb[$k[0]][$t][] = $k;
            }
        }
        foreach ($tmdb as $tipo => $porTmdb) {
            foreach (Base::linhas('select distinct on (tmdb_id) id, tmdb_id from play.titulo where tipo = ? and tmdb_id = any(cast(? as int[]))
                                    order by tmdb_id, ativo desc, id', [$tipo, Base::arr(array_keys($porTmdb))]) as $t) {
                foreach ($porTmdb[(int) $t['tmdb_id']] ?? [] as $k) {
                    $titulo[$k] = (int) $t['id'];
                }
            }
        }
        return [$titulo, $episodio];
    }

    private static function ts(int $ms): string
    {
        return $ms > 0 ? sprintf('%.3F', $ms / 1000) : (string) time();
    }

    public static function gravar(int $perfil, array $n): array
    {
        [$titulo, $episodio] = self::mapear($n);
        $t = fn(string $tipo, int $painel) => $titulo[$tipo . $painel] ?? null;
        $conta = Base::contaId();
        $c = ['historico' => 0, 'lista' => 0, 'notas' => 0, 'aberturas' => 0, 'versoes' => 0, 'favoritos' => 0, 'progresso' => 0,
              'preferencias' => 0, 'nao_encontrados' => 0];

        Base::transacao(function () use ($perfil, $n, $t, $episodio, $conta, &$c): void {
            // Histórico (uma linha por título na v10) + séries seguidas + episódio terminado = "próximo" no Continuar.
            foreach ($n['historico'] as $h) {
                $id = $t($h['tipo'], $h['painel']);
                if (!$id) {
                    $c['nao_encontrados']++;
                    continue;
                }
                $quando = self::ts($h['quando']);
                $c['historico'] += Base::exec('
                    insert into play.historico (perfil_id, titulo_id, temporada, episodio, inicio, fim, assistido_s, terminado)
                    select ?, ?, ?, ?, to_timestamp(cast(? as double precision)), to_timestamp(cast(? as double precision)), 0, ?
                     where not exists (select 1 from play.historico where perfil_id = ? and titulo_id = ?
                                          and inicio = to_timestamp(cast(? as double precision)))',
                    [$perfil, $id, $h['temporada'], $h['episodio'], $quando, $quando, $h['terminado'], $perfil, $id, $quando]);
                if ($h['tipo'] === 's') {
                    Base::exec('insert into play.serie_seguida (perfil_id, titulo_id, desde) values (?, ?, to_timestamp(cast(? as double precision)))
                                on conflict do nothing', [$perfil, $id, $quando]);
                    if ($h['terminado'] && $h['temporada'] && $h['episodio']) {
                        $c['progresso'] += self::progresso($perfil, $id, $h['painel'], $h['temporada'], $h['episodio'], 0, 0, true, $quando);
                    }
                }
            }

            // Posições (filmes e episódios); o mais novo vence.
            foreach ($n['progresso'] as $x) {
                if ($x['tipo'] === 'm') {
                    $id = $t('m', $x['painel']);
                    if ($id) {
                        $c['progresso'] += self::progresso($perfil, $id, $x['painel'], null, null, $x['pos'], $x['dur'], false, self::ts($x['quando']));
                    } else {
                        $c['nao_encontrados']++;
                    }
                    continue;
                }
                $ep = $episodio[$x['painel']] ?? null;
                $serie = $x['serie'] ?? $ep['serie'] ?? null;
                $temp = $x['temporada'] ?? $ep['temporada'] ?? null;
                $num = $x['episodio'] ?? $ep['numero'] ?? null;
                $id = $serie ? $t('s', $serie) : null;
                if (!$id || !$temp || !$num) {
                    $c['nao_encontrados']++;
                    continue;
                }
                $quando = self::ts($x['quando']);
                Base::exec('insert into play.progresso_ep (perfil_id, titulo_id, temporada, episodio, posicao_s, duracao_s, terminado, atualizado_em)
                            values (?, ?, ?, ?, ?, ?, false, to_timestamp(cast(? as double precision)))
                            on conflict (perfil_id, titulo_id, temporada, episodio) do update set
                                posicao_s = excluded.posicao_s, duracao_s = excluded.duracao_s, terminado = false, atualizado_em = excluded.atualizado_em
                             where play.progresso_ep.atualizado_em < excluded.atualizado_em',
                    [$perfil, $id, $temp, $num, $x['pos'], $x['dur'], $quando]);
                Base::exec('insert into play.serie_seguida (perfil_id, titulo_id, desde) values (?, ?, to_timestamp(cast(? as double precision)))
                            on conflict do nothing', [$perfil, $id, $quando]);
                $c['progresso'] += self::progresso($perfil, $id, $serie, $temp, $num, $x['pos'], $x['dur'], false, $quando);
            }

            foreach ($n['lista'] as $x) {
                if ($id = $t($x['tipo'], $x['painel'])) {
                    $c['lista'] += Base::exec('insert into play.lista (perfil_id, titulo_id, adicionado_em) values (?, ?, to_timestamp(cast(? as double precision)))
                                               on conflict do nothing', [$perfil, $id, self::ts($x['quando'])]);
                }
            }
            foreach ($n['notas'] as $x) {
                if ($id = $t($x['tipo'], $x['painel'])) {
                    $c['notas'] += Base::exec('insert into play.nota (perfil_id, titulo_id, valor, em) values (?, ?, ?, to_timestamp(cast(? as double precision)))
                                               on conflict do nothing', [$perfil, $id, $x['valor'], self::ts($x['quando'])]);
                }
            }
            foreach ($n['aberturas'] as $x) {
                if (($id = $t('s', $x['serie'])) && Uso::aberturaValida($x['inicio'], $x['fim'])) {
                    $c['aberturas'] += Base::exec('insert into play.abertura (titulo_id, inicio_s, fim_s) values (?, ?, ?) on conflict do nothing',
                        [$id, $x['inicio'], $x['fim']]);
                }
            }
            foreach ($n['versoes'] as $x) {
                if ($id = $t($x['tipo'], $x['painel'])) {
                    $c['versoes'] += Base::exec('insert into play.versao_escolhida (perfil_id, titulo_id, idioma) values (?, ?, ?) on conflict do nothing',
                        [$perfil, $id, $x['idioma']]);
                }
            }
            if ($n['favoritos'] && $conta) {
                $existem = array_flip(array_map('intval', Base::coluna('select painel_id from play.canal where conta_id = ? and painel_id = any(cast(? as int[]))',
                    [$conta, Base::arr($n['favoritos'])])));
                foreach ($n['favoritos'] as $i => $canal) {
                    if (isset($existem[$canal])) {
                        $c['favoritos'] += Base::exec('insert into play.canal_fav (perfil_id, conta_id, canal_id, ordem) values (?, ?, ?, ?) on conflict do nothing',
                            [$perfil, $conta, $canal, $i]);
                    }
                }
            }
            foreach ($n['prefs'] as $chave => $valor) {
                $c['preferencias'] += Base::exec('insert into play.preferencia (perfil_id, chave, valor) values (?, ?, cast(? as jsonb)) on conflict do nothing',
                    [$perfil, $chave, json_encode($valor)]);
            }
            foreach ($n['atrasos'] as $x) {
                $id = $x['tipo'] === 'm' ? $t('m', $x['painel']) : (($ep = $episodio[$x['painel']] ?? null) ? $t('s', $ep['serie']) : null);
                if ($id) {
                    $c['preferencias'] += Base::exec('insert into play.preferencia (perfil_id, chave, valor) values (?, ?, cast(? as jsonb)) on conflict do nothing',
                        [$perfil, 'legenda_atraso:' . $id . ':' . $x['arquivo'], json_encode($x['atraso'])]);
                }
            }
            // "Tirado do Continuar" na v10: oculta se não tocou depois.
            foreach ($n['ocultos'] as $x) {
                $id = $x['tipo'] === 'e' ? (($ep = $episodio[$x['painel']] ?? null) ? $t('s', $ep['serie']) : null) : $t($x['tipo'], $x['painel']);
                if ($id) {
                    Base::exec('update play.progresso set oculto = true
                                 where perfil_id = ? and titulo_id = ? and atualizado_em <= to_timestamp(cast(? as double precision))',
                        [$perfil, $id, self::ts($x['quando'])]);
                }
            }
        });
        return $c;
    }

    /** Upsert do progresso do título, só se for mais novo que o que já existe. */
    private static function progresso(int $perfil, int $id, ?int $versao, ?int $temp, ?int $ep, int $pos, int $dur, bool $terminado, string $quando): int
    {
        return Base::exec('
            insert into play.progresso (perfil_id, titulo_id, versao_id, temporada, episodio, posicao_s, duracao_s, terminado, atualizado_em)
            values (?, ?, ?, ?, ?, ?, ?, ?, to_timestamp(cast(? as double precision)))
            on conflict (perfil_id, titulo_id) do update set
                versao_id = coalesce(excluded.versao_id, play.progresso.versao_id), temporada = excluded.temporada, episodio = excluded.episodio,
                posicao_s = excluded.posicao_s, duracao_s = excluded.duracao_s, terminado = excluded.terminado, atualizado_em = excluded.atualizado_em
             where play.progresso.atualizado_em < excluded.atualizado_em',
            [$perfil, $id, $versao, $temp, $ep, $pos, $dur, $terminado, $quando]);
    }
}
