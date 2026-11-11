<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * AniList (09/11/2026): recomendações de anime votadas pela comunidade ("quem gostou de X indica Y"). O MovieLens e o
 * Trakt cobrem mal anime (Crunchyroll e os animes da lista).
 *
 *  - Ponte AniList <-> TMDB: anime-list-full.json do projeto open source Fribb/anime-lists (GitHub), baixado 1x/semana
 *    para var/play/anime/. Cada temporada no AniList é uma entrada; no TMDB a série é uma só (junta até 4 temporadas).
 *  - API GraphQL pública, sem chave; limite atual 30 pedidos/min: 10 animes por pedido com apelidos (a0..a9).
 *  - Grava play.cache 'cf:anilist:<s|m>:<tmdb>' = {v:[[tmdb, tipo]...]} (só o que tem TMDB), refeito após 60 dias.
 */
final class AniList
{
    private const API = 'https://graphql.anilist.co';
    private const MAPA = 'https://raw.githubusercontent.com/Fribb/anime-lists/master/anime-list-full.json';
    private const POR_PEDIDO = 10;
    private const PAUSA_S = 2.5;
    private const VALIDADE_DIAS = 60;

    /** Lista do Fribb -> [tmdbParaAl: ['s1234' => [alIds]], alParaTmdb: [alId => 's1234']] (puro). */
    public static function indexar(array $lista): array
    {
        $porTmdb = [];
        $paraTmdb = [];
        foreach ($lista as $x) {
            $al = (int) ($x['anilist_id'] ?? 0);
            $t = $x['themoviedb_id'] ?? null;
            if ($al <= 0 || !is_array($t)) {
                continue;
            }
            if (!empty($t['tv'])) {
                $k = 's' . (int) (is_array($t['tv']) ? $t['tv'][0] : $t['tv']);
            } elseif (!empty($t['movie'])) {
                $k = 'm' . (int) (is_array($t['movie']) ? $t['movie'][0] : $t['movie']);
            } else {
                continue;
            }
            $porTmdb[$k][] = $al;
            $paraTmdb[$al] = $k;
        }
        return [$porTmdb, $paraTmdb];
    }

    /**
     * Recomendações das temporadas de um título -> [[tmdb, tipo]] pela soma dos votos (puro).
     * $recs = [alId => [[alRecomendado, votos], ...]].
     */
    public static function agregar(array $alIds, array $recs, array $paraTmdb, string $proprio, int $max = 30): array
    {
        $soma = [];
        foreach ($alIds as $al) {
            foreach ($recs[$al] ?? [] as [$outro, $votos]) {
                $k = $paraTmdb[$outro] ?? null;
                if ($k === null || $k === $proprio || $votos <= 0) {
                    continue;
                }
                $soma[$k] = ($soma[$k] ?? 0) + $votos;
            }
        }
        arsort($soma);
        return array_map(fn($k) => [(int) substr($k, 1), $k[0]], array_slice(array_keys($soma), 0, $max));
    }

    /** Mapa do Fribb (baixa de novo depois de 7 dias; falha = usa o que tem). */
    private static function mapa(): array
    {
        $arq = Banco::pasta('anime') . '/anime-list-full.json';
        if (!is_file($arq) || filemtime($arq) < time() - 7 * 86400) {
            try {
                $r = Http::get(self::MAPA, [], 60);
                if ($r['status'] === 200 && is_array(json_decode($r['corpo'], true))) {
                    file_put_contents($arq . '.tmp', $r['corpo']);
                    rename($arq . '.tmp', $arq);
                }
            } catch (\RuntimeException $e) {
                Log::info('anilist: mapa do Fribb: ' . $e->getMessage());
            }
        }
        $l = is_file($arq) ? json_decode((string) file_get_contents($arq), true) : null;
        return is_array($l) ? self::indexar($l) : [[], []];
    }

    /** Recomendações de vários animes num pedido. null = parar (limite/erro). @return array<int, list<array{0:int,1:int}>>|null */
    private static function pedir(array $alIds): ?array
    {
        $partes = [];
        foreach (array_values($alIds) as $i => $al) {
            $partes[] = "a$i: Media(id: " . (int) $al . ', type: ANIME) { id recommendations(perPage: 25, sort: RATING_DESC) { nodes { rating mediaRecommendation { id } } } }';
        }
        try {
            $r = Http::postJson(self::API, ['query' => '{ ' . implode(' ', $partes) . ' }'], [], 30);
        } catch (HttpErroStatus $e) {
            if ($e->status === 404 && count($alIds) > 1) {
                // um id que o AniList não tem derruba o pedido inteiro: um por um
                $saida = [];
                foreach ($alIds as $al) {
                    usleep((int) (self::PAUSA_S * 1e6));
                    $um = self::pedir([$al]);
                    if ($um === null) {
                        return null;
                    }
                    $saida += $um;
                }
                return $saida;
            }
            if ($e->status === 404) {
                return [(int) $alIds[0] => []];
            }
            Log::info('anilist: ' . $e->getMessage());
            return null;
        } catch (\RuntimeException $e) {
            Log::info('anilist: ' . $e->getMessage());
            return null;
        }
        $saida = [];
        foreach ((array) ($r['data'] ?? []) as $m) {
            if (!is_array($m) || empty($m['id'])) {
                continue;
            }
            $saida[(int) $m['id']] = [];
            foreach ($m['recommendations']['nodes'] ?? [] as $n) {
                if (!empty($n['mediaRecommendation']['id'])) {
                    $saida[(int) $m['id']][] = [(int) $n['mediaRecommendation']['id'], (int) ($n['rating'] ?? 0)];
                }
            }
        }
        foreach ($alIds as $al) {
            $saida[(int) $al] ??= [];
        }
        return $saida;
    }

    public static function tarefa(): array
    {
        [$porTmdb, $paraTmdb] = self::mapa();
        if (!$porTmdb) {
            return ['msg' => 'sem o mapa AniList-TMDB (Fribb/anime-lists)'];
        }
        $pdo = Db::pdo();
        $chaves = array_keys($porTmdb);
        $sel = $pdo->prepare("
            select t.tipo, t.tmdb_id
              from play.titulo t
             where t.ativo and t.tmdb_id is not null and (t.tipo || t.tmdb_id) = any(cast(? as text[]))
               and not exists (select 1 from play.cache c where c.chave = 'cf:anilist:' || t.tipo || ':' || t.tmdb_id
                                 and c.em > now() - make_interval(days => ?))
             group by t.tipo, t.tmdb_id
             order by bool_or(exists (select 1 from play.historico h where h.titulo_id = t.id)
                              or exists (select 1 from play.lista l where l.titulo_id = t.id)) desc,
                      max(coalesce((t.jw->>'imdb_votos')::bigint, 0)) desc");
        $sel->execute(['{' . implode(',', $chaves) . '}', self::VALIDADE_DIAS]);
        $fila = $sel->fetchAll();
        $feitos = 0;
        $com = 0;
        $grupo = [];
        $ids = [];
        $parar = false;
        $processar = function () use (&$grupo, &$ids, &$feitos, &$com, &$parar, $porTmdb, $paraTmdb): void {
            if (!$grupo) {
                return;
            }
            $recs = self::pedir($ids);
            if ($recs === null) {
                $parar = true;
                return;
            }
            foreach ($grupo as $k) {
                $v = self::agregar(array_slice($porTmdb[$k], 0, 4), $recs, $paraTmdb, $k);
                Banco::guardar('cf:anilist:' . $k[0] . ':' . substr($k, 1), ['v' => $v]);
                $feitos++;
                $com += $v ? 1 : 0;
            }
            [$grupo, $ids] = [[], []];
            usleep((int) (self::PAUSA_S * 1e6));
        };
        foreach (array_slice($fila, 0, 600) as $f) {
            $k = $f['tipo'] . $f['tmdb_id'];
            $al = array_slice($porTmdb[$k], 0, 4);
            if (count($ids) + count($al) > self::POR_PEDIDO) {
                $processar();
                if ($parar || Tarefas::ceder()) {
                    break;
                }
            }
            $grupo[] = $k;
            $ids = array_merge($ids, $al);
        }
        if (!$parar) {
            $processar();
        }
        if ($feitos) {
            Banco::guardar('cf:anilist', ['em' => date('c')]);
        }
        return ['msg' => "$feitos animes ($com com recomendações); faltam " . max(0, count($fila) - $feitos)];
    }
}
