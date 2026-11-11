<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Filtragem colaborativa (09/11/2026): "quem gostou de A também gostou de B".
 *
 *  - Filmes: MovieLens 32M treinado com a biblioteca open source `implicit` (ALS) no container casa-rec (Python,
 *    ../rec/rec.py), que grava em play.cache 'cf:ml:<tmdb>' = {v:[[tmdb, similaridade]...]} só filmes do catálogo.
 *  - Séries (e filmes fora do MovieLens): relacionados do Trakt (Dados\Trakt) em 'cf:trakt:<s|m>:<tmdb>'.
 *  - Anime: recomendações da comunidade do AniList (Dados\AniList) em 'cf:anilist:<s|m>:<tmdb>'.
 *  - "Clima" do filme: Tag Genome do MovieLens 25M (1.128 características, casa-rec) em 'cf:tg:<tmdb>'.
 *
 * O recomendador usa isso como mais uma fonte de candidatos por sinal (a mais forte), ao lado das do TMDB e do
 * JustWatch; gosto, qualidade e variedade continuam valendo depois.
 */
final class Colaborativo
{
    /** Peso de cada fonte em relação ao peso do sinal (TMDB "recomendações" = 1,0). tg = "clima" (conteúdo), não comportamento. */
    public const PESO = ['ml' => 1.25, 'trakt' => 0.8, 'anilist' => 0.9, 'tg' => 0.7];
    /** Fontes com [tmdb, similaridade] só de filmes (casa-rec) e com [tmdb, tipo] (tarefas trakt/anilist). */
    private const SO_FILMES = ['ml', 'tg'];
    private const COM_TIPO = ['trakt', 'anilist'];

    /**
     * Vizinhos guardados para os pares [tipo, tmdb]. @return array<string, array{ml?:list<array{0:int,1:string}>, trakt?:list<array{0:int,1:string}>}>
     * chave "m603" / "s1396"; cada vizinho = [tmdb, tipo].
     */
    public static function vizinhos(array $pares): array
    {
        $chaves = [];
        foreach ($pares as [$tipo, $tmdb]) {
            if ($tmdb > 0) {
                if ($tipo === 'm') {
                    foreach (self::SO_FILMES as $f) {
                        $chaves["cf:$f:$tmdb"] = [$tipo . $tmdb, $f];
                    }
                }
                foreach (self::COM_TIPO as $f) {
                    $chaves["cf:$f:$tipo:$tmdb"] = [$tipo . $tmdb, $f];
                }
            }
        }
        if (!$chaves) {
            return [];
        }
        $st = Db::pdo()->prepare('select chave, dados from play.cache where chave = any(cast(? as text[]))');
        $st->execute(['{' . implode(',', array_map(fn($k) => '"' . $k . '"', array_keys($chaves))) . '}']);
        $saida = [];
        foreach ($st->fetchAll() as $l) {
            [$k, $fonte] = $chaves[$l['chave']];
            $v = json_decode((string) $l['dados'], true)['v'] ?? [];
            $saida[$k][$fonte] = array_map(fn($x) => [(int) $x[0], in_array($fonte, self::SO_FILMES, true) ? 'm' : (string) $x[1]], $v);
        }
        return $saida;
    }

    /** Títulos do catálogo no formato TMDB que o pontuar() entende. @return array<string, array> chave "m603". */
    public static function registros(array $vizinhos): array
    {
        $ids = ['m' => [], 's' => []];
        foreach ($vizinhos as $fontes) {
            foreach ($fontes as $lista) {
                foreach ($lista as [$tmdb, $tipo]) {
                    $ids[$tipo][$tmdb] = true;
                }
            }
        }
        $saida = [];
        foreach ($ids as $tipo => $l) {
            if (!$l) {
                continue;
            }
            $st = Db::pdo()->prepare("select distinct on (tmdb_id) tmdb_id, nome, ano, generos, jw->>'imdb' as imdb,
                                             jw->>'imdb_votos' as votos, nota_painel
                                        from play.titulo where ativo and tipo = ? and tmdb_id = any(cast(? as int[]))
                                       order by tmdb_id, (jw->>'imdb') is null, id");
            $st->execute([$tipo, Banco::ints(array_keys($l))]);
            foreach ($st->fetchAll() as $t) {
                $saida[$tipo . $t['tmdb_id']] = self::registro($tipo, $t);
            }
        }
        return $saida;
    }

    /** Linha de play.titulo -> registro TMDB (puro). */
    public static function registro(string $tipo, array $t): array
    {
        $data = $t['ano'] ? $t['ano'] . '-01-01' : '';
        $r = ['id' => (int) $t['tmdb_id'], 'media_type' => $tipo === 's' ? 'tv' : 'movie',
              'genre_ids' => is_array($t['generos']) ? array_map('intval', $t['generos']) : Banco::lerInts($t['generos']),
              'vote_average' => (float) ($t['imdb'] ?? 0) ?: (float) ($t['nota_painel'] ?? 0),
              'vote_count' => (int) ($t['votos'] ?? 0) ?: 300, '_cf' => 1];
        return $r + ($tipo === 's' ? ['name' => $t['nome'], 'first_air_date' => $data] : ['title' => $t['nome'], 'release_date' => $data]);
    }

    /**
     * Conjuntos de candidatos para o pontuar() (puro). sinais = [{id, w, tipo, tmdb}]; só os positivos geram.
     * Vizinho fora do catálogo é ignorado (a TV não teria o que tocar).
     */
    public static function conjuntos(array $sinais, array $vizinhos, array $registros, float $fator = 1.0): array
    {
        $sets = [];
        foreach ($sinais as $s) {
            if ($s['w'] <= 0 || empty($s['tmdb'])) {
                continue;
            }
            foreach ($vizinhos[$s['tipo'] . $s['tmdb']] ?? [] as $fonte => $lista) {
                $l = [];
                foreach ($lista as [$tmdb, $tipo]) {
                    if (isset($registros[$tipo . $tmdb])) {
                        $l[] = $registros[$tipo . $tmdb];
                    }
                }
                if ($l) {
                    // tipo null: o vizinho do Trakt pode ser de outro tipo; a mídia vem do próprio registro
                    $sets[] = ['sinal' => $s['id'], 'w' => $s['w'] * self::PESO[$fonte] * $fator, 'tipo' => null, 'lista' => $l, 'fonte' => $fonte];
                }
            }
        }
        return $sets;
    }

    /** Para a chave da recomendação: muda quando os vizinhos são refeitos (no máximo 1x por dia). */
    public static function versao(): string
    {
        $v = Db::pdo()->query("select string_agg(chave || '=' || left(coalesce(dados->>'vizinhos_em', dados->>'em', ''), 10), ',' order by chave)
                                 from play.cache where chave in ('cf:ml', 'cf:tg', 'cf:trakt', 'cf:anilist')")->fetchColumn();
        return (string) $v;
    }

    /** Resumo para o painel/CLI. */
    public static function estado(): array
    {
        $pdo = Db::pdo();
        $ml = $pdo->query("select dados from play.cache where chave = 'cf:ml'")->fetchColumn();
        $tg = $pdo->query("select dados from play.cache where chave = 'cf:tg'")->fetchColumn();
        $n = $pdo->query("select count(*) filter (where chave like 'cf:trakt:%') as trakt,
                                 count(*) filter (where chave like 'cf:trakt:%' and jsonb_array_length(dados->'v') > 0) as trakt_com,
                                 count(*) filter (where chave like 'cf:anilist:%') as anilist,
                                 count(*) filter (where chave like 'cf:anilist:%' and jsonb_array_length(dados->'v') > 0) as anilist_com
                            from play.cache where chave like 'cf:%'")->fetch();
        return ['movielens' => $ml ? json_decode((string) $ml, true) : null, 'genoma' => $tg ? json_decode((string) $tg, true) : null,
                'trakt' => (int) $n['trakt'], 'trakt_com' => (int) $n['trakt_com'], 'trakt_chave' => Trakt::chave() !== null,
                'anilist' => (int) $n['anilist'], 'anilist_com' => (int) $n['anilist_com']];
    }
}
