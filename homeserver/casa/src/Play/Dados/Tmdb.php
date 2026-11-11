<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;
use RuntimeException;

/**
 * TMDB: cliente (token TMDB_TOKEN do .env, ~4 pedidos/s, respeita o 429) e a tarefa "tmdb", que
 * completa os títulos aos poucos (detalhes pt-BR com elenco, palavras-chave, trailer, ids externos,
 * classificação do Brasil, coleção/saga, próximo episódio). A primeira carga (44 mil títulos) leva
 * horas: a tarefa trabalha em lotes, cede a vez e retoma de onde parou (o que falta = detalhes_em nulo).
 */
final class Tmdb
{
    private const BASE = 'https://api.themoviedb.org/3/';
    private const ESPACO_S = 0.1;         // ~10 pedidos/s (o TMDB aceita ~50/s)
    private const LOTE_S = 240;
    private const SEMANA = 7 * 86400;

    private static float $ultimo = 0.0;

    public static function configurado(): bool
    {
        return (string) getenv('TMDB_TOKEN') !== '';
    }

    /** GET na API (null = 404). Token v4 (Bearer) ou chave v3 (32 hex, vai como api_key). */
    public static function get(string $caminho): ?array
    {
        $token = (string) getenv('TMDB_TOKEN');
        if ($token === '') {
            throw new RuntimeException('TMDB_TOKEN não configurado no .env');
        }
        Http::segredo($token);
        $url = self::BASE . ltrim($caminho, '/');
        $cab = [];
        if (preg_match('/^[0-9a-f]{32}$/i', $token)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'api_key=' . $token;
        } else {
            $cab['Authorization'] = 'Bearer ' . $token;
        }
        for ($tentativa = 1; ; $tentativa++) {
            $espera = self::$ultimo + self::ESPACO_S - microtime(true);
            if ($espera > 0) {
                usleep((int) ($espera * 1e6));
            }
            self::$ultimo = microtime(true);
            try {
                $j = Http::json($url, $cab, 20);
                return is_array($j) ? $j : null;
            } catch (HttpErroStatus $e) {
                if ($e->status === 404) {
                    return null;
                }
                if ($e->status === 429 && $tentativa <= 3) {
                    sleep(max(1, min(30, (int) ($e->cab['retry-after'] ?? 5))));
                    continue;
                }
                if ($e->status >= 500 && $tentativa <= 2) {
                    sleep(2);
                    continue;
                }
                throw $e;
            } catch (RuntimeException $e) {
                // rede (timeout, DNS, conexão recusada): tenta de novo antes de desistir
                if ($tentativa <= 2) {
                    sleep(2 * $tentativa);
                    continue;
                }
                throw $e;
            }
        }
    }

    /** GET guardado em play.cache (padrão uma semana, como o cachedTmdb da v10). */
    public static function cache(string $caminho, int $ttl = self::SEMANA, ?callable $enxugar = null): array
    {
        return Banco::lembrar('tmdb:' . $caminho, $ttl, function () use ($caminho, $enxugar) {
            $d = self::get($caminho) ?? [];
            return $enxugar ? $enxugar($d) : $d;
        }) ?? [];
    }

    /** Lista de resultados enxuta (slimList da v10). */
    public static function enxugarLista(array $d): array
    {
        $item = fn(array $r) => array_intersect_key($r, array_flip(['id', 'media_type', 'title', 'name', 'original_title', 'original_name',
            'release_date', 'first_air_date', 'genre_ids', 'vote_average', 'vote_count', 'popularity', 'poster_path', 'backdrop_path']));
        $saida = ['results' => array_map($item, array_slice($d['results'] ?? [], 0, 20))];
        if (isset($d['cast'])) {
            $cast = $d['cast'];
            usort($cast, fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));
            $saida['cast'] = array_map($item, array_slice($cast, 0, 60));
        }
        if (isset($d['genres'])) {
            $saida['genres'] = $d['genres'];
        }
        return $saida;
    }

    public static function detalhesUrl(string $tipo, int $tmdb): string
    {
        $tv = $tipo === 's';
        return ($tv ? 'tv/' : 'movie/') . $tmdb . '?language=pt-BR&include_video_language=pt,en'
            . '&append_to_response=credits,keywords,videos,external_ids,' . ($tv ? 'content_ratings' : 'release_dates');
    }

    // ------------------------------------------------------------ tarefa

    public static function tarefa(): array
    {
        if (!self::configurado()) {
            return ['msg' => 'TMDB_TOKEN não configurado', 'proxima' => time() + 3600];
        }
        $ini = time();
        $feitos = 0;
        $com = 0;
        $falhas = 0;
        $seguidas = 0;
        $pulados = [];                       // falharam neste lote: não voltam na mesma rodada
        do {
            $ids = array_values(array_diff(self::pendentes(40 + count($pulados)), $pulados));
            foreach ($ids as $i => $id) {
                try {
                    $com += self::atualizarTitulo($id) ? 1 : 0;
                    $seguidas = 0;
                } catch (\PDOException $e) {
                    throw $e;
                } catch (RuntimeException $e) {
                    // falha de rede num título não derruba o lote; muitas seguidas = rede fora, para e volta em 1 min
                    $falhas++;
                    $pulados[] = $id;
                    if (++$seguidas >= 5) {
                        throw new RuntimeException('TMDB fora do ar? 5 falhas seguidas: ' . Http::mascarar($e->getMessage()));
                    }
                }
                $feitos++;
                if ($i % 5 === 4 && Tarefas::ceder()) {
                    break 2;
                }
            }
        } while ($ids && time() - $ini < self::LOTE_S && !Tarefas::parar());
        $faltam = (int) Db::pdo()->query('select count(*) from play.titulo where ativo and detalhes_em is null')->fetchColumn();
        $msg = "$feitos título(s), $com com TMDB" . ($falhas ? ", $falhas falha(s) de rede" : '') . "; faltam $faltam sem detalhes";
        // ainda há fila: volta logo (as outras tarefas vencidas passam antes)
        return ['msg' => $msg, 'proxima' => $ids ? time() + 5 : null];
    }

    /** Prioridade: Top 10, continuar/seguidas/lista, sem detalhes, recém-adicionados, o resto. */
    private static function pendentes(int $n): array
    {
        $st = Db::pdo()->prepare("
            with top as (select distinct titulo_id from play.top10 where coletado_em > now() - interval '2 days' and titulo_id is not null),
                 uso as (select titulo_id from play.progresso where not terminado and atualizado_em > now() - interval '60 days'
                         union select titulo_id from play.serie_seguida
                         union select titulo_id from play.lista)
            select t.id from play.titulo t
             where t.ativo and (
                   t.detalhes_em is null
                or t.detalhes_em < now() - interval '30 days'
                or (t.tipo = 's' and t.detalhes_em < now() - interval '1 day'
                    and coalesce(t.detalhes->>'status', '') in ('Returning Series', 'In Production', 'Planned')
                    and t.id in (select titulo_id from uso)))
             order by (t.id in (select titulo_id from top)) desc,
                      (t.id in (select titulo_id from uso)) desc,
                      (t.detalhes_em is null) desc,
                      t.adicionado_em desc nulls last
             limit ?");
        $st->execute([$n]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Detalhes TMDB de um título (busca o id por nome+ano quando o painel não deu). Também usado sob
     * demanda pelo recomendador. Devolve false quando o TMDB não conhece o título.
     */
    public static function atualizarTitulo(int $id): bool
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare('select id, tipo, nome, ano, tmdb_id from play.titulo where id = ?');
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) {
            return false;
        }
        $tipo = $t['tipo'] === 's' ? 's' : 'm';
        $tmdb = $t['tmdb_id'] !== null ? (int) $t['tmdb_id'] : self::buscarId($tipo, (string) $t['nome'], (int) $t['ano']);
        if (!$tmdb && $tipo === 'm') {
            $tmdb = self::tmdbDoPainel($id);
        }
        $d = $tmdb ? self::get(self::detalhesUrl($tipo, $tmdb)) : null;
        if (!$d || empty($d['id'])) {
            $pdo->prepare("update play.titulo set detalhes = detalhes || '{\"sem_tmdb\": true}'::jsonb, detalhes_em = now() where id = ?")->execute([$id]);
            return false;
        }
        $e = self::enxuto($d, $tipo);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                update play.titulo set tmdb_id = ?, imdb_id = coalesce(?, imdb_id), poster = coalesce(?, poster), fundo = coalesce(?, fundo),
                       sinopse = coalesce(?, sinopse), generos = cast(? as int[]), colecao_id = ?, ano = coalesce(ano, ?),
                       detalhes = cast(? as jsonb), detalhes_em = now(), atualizado_em = now()
                 where id = ?")
                ->execute([$e['tmdb'], $e['imdb'], $e['poster'], $e['fundo'], $e['sinopse'], Banco::ints($e['generos']),
                           $e['colecao']['id'] ?? null, $e['ano'] ?: null, Banco::json($e['detalhes']), $id]);
            if ($e['pessoas']) {
                $pdo->prepare("
                    insert into play.pessoa (tmdb_id, nome, nome_norm, foto)
                    select distinct on (x.id) x.id, x.nome, x.k, x.foto from jsonb_to_recordset(cast(? as jsonb)) as x(id int, nome text, k text, foto text)
                    on conflict (tmdb_id) do update set nome = excluded.nome, nome_norm = excluded.nome_norm, foto = coalesce(excluded.foto, play.pessoa.foto)")
                    ->execute([Banco::json(array_map(fn($p) => ['id' => $p['id'], 'nome' => $p['nome'], 'k' => Texto::norm($p['nome']), 'foto' => $p['foto']], $e['pessoas']))]);
            }
            $pdo->prepare('delete from play.titulo_pessoa where titulo_id = ?')->execute([$id]);
            if ($e['pessoas']) {
                $pdo->prepare("
                    insert into play.titulo_pessoa (titulo_id, pessoa_id, papel, ordem, personagem)
                    select ?, x.id, x.papel, x.ordem, x.personagem from jsonb_to_recordset(cast(? as jsonb)) as x(id int, papel text, ordem int, personagem text)
                    on conflict (titulo_id, pessoa_id, papel) do nothing")
                    ->execute([$id, Banco::json($e['pessoas'])]);
            }
            $pdo->commit();
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        if (!empty($e['colecao']['id'])) {
            self::colecao((int) $e['colecao']['id']);
        }
        return true;
    }

    /** get_vod_info do painel por hora (a lista não traz o tmdb; só quando a busca por nome falha). */
    private const PAINEL_POR_HORA = 300;
    private static array $painelUso = ['hora' => 0, 'n' => 0];

    /** tmdb_id que o painel guarda no get_vod_info do filme (versão principal). */
    private static function tmdbDoPainel(int $tituloId): ?int
    {
        $hora = intdiv(time(), 3600);
        if (self::$painelUso['hora'] !== $hora) {
            self::$painelUso = ['hora' => $hora, 'n' => 0];
        }
        $conta = Xtream::contaAtiva();
        if (!$conta || self::$painelUso['n'] >= self::PAINEL_POR_HORA) {
            return null;
        }
        $st = Db::pdo()->prepare("select painel_id from play.versao where titulo_id = ? and conta_id = ? and tipo = 'm' and ativo
                                   order by uhd, (idioma = 'leg'), painel_id limit 1");
        $st->execute([$tituloId, $conta['id']]);
        $p = $st->fetchColumn();
        if ($p === false) {
            return null;
        }
        self::$painelUso['n']++;
        try {
            $info = Xtream::api($conta, 'get_vod_info', ['vod_id' => (int) $p], 20);
        } catch (\Throwable) {
            return null;
        }
        $id = (int) ($info['info']['tmdb_id'] ?? $info['info']['tmdb'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** Saga TMDB em play.colecao (partes em ordem de lançamento), renovada a cada 30 dias. */
    public static function colecao(int $id): void
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare("select atualizado_em > now() - interval '30 days' from play.colecao where id = ?");
        $st->execute([$id]);
        if (Banco::bool($st->fetchColumn())) {
            return;
        }
        $c = self::get("collection/$id?language=pt-BR");
        if (!$c || empty($c['name'])) {
            return;
        }
        $partes = array_values(array_filter($c['parts'] ?? [], fn($p) => !empty($p['id'])));
        usort($partes, fn($a, $b) => [($a['release_date'] ?? '') === '' ? 1 : 0, $a['release_date'] ?? ''] <=> [($b['release_date'] ?? '') === '' ? 1 : 0, $b['release_date'] ?? '']);
        $lista = [];
        foreach ($partes as $i => $p) {
            $lista[] = ['tmdb' => (int) $p['id'], 'nome' => (string) ($p['title'] ?? $p['name'] ?? ''), 'ano' => (int) substr((string) ($p['release_date'] ?? ''), 0, 4) ?: null,
                        'ordem' => $i + 1, 'poster' => $p['poster_path'] ?? null];
        }
        $pdo->prepare("insert into play.colecao (id, nome, partes, fundo, atualizado_em) values (?, ?, cast(? as jsonb), ?, now())
                       on conflict (id) do update set nome = excluded.nome, partes = excluded.partes, fundo = excluded.fundo, atualizado_em = now()")
            ->execute([$id, (string) $c['name'], Banco::json($lista), $c['backdrop_path'] ?? null]);
    }

    /**
     * Id TMDB pelo nome (+ano): o mesmo nome (pt-BR ou original) e ano ±1; sem ano bate, só o nome
     * (o ano das séries no painel costuma ser o da última temporada).
     */
    public static function buscarId(string $tipo, string $nome, int $ano): ?int
    {
        $q = Texto::limparNome($nome);
        if ($q === '') {
            return null;
        }
        $base = ($tipo === 's' ? 'search/tv' : 'search/movie') . '?language=pt-BR&include_adult=false&query=' . rawurlencode($q);
        $comAno = $ano ? self::get($base . ($tipo === 's' ? '&first_air_date_year=' : '&year=') . $ano) : null;
        $id = self::escolherBusca($comAno['results'] ?? [], $nome, $ano, $tipo);
        if ($id === null) {
            $id = self::escolherBusca(self::get($base)['results'] ?? [], $nome, $ano, $tipo);
        }
        return $id;
    }

    /** Escolha do resultado de busca (pura, testada). */
    public static function escolherBusca(array $resultados, string $nome, int $ano, string $tipo): ?int
    {
        $k = Texto::norm($nome);
        $melhor = null;
        $pontos = -1;
        foreach (array_slice($resultados, 0, 8) as $i => $r) {
            $nomes = [Texto::norm($r['title'] ?? $r['name'] ?? ''), Texto::norm($r['original_title'] ?? $r['original_name'] ?? '')];
            $a = (int) substr((string) ($r['release_date'] ?? $r['first_air_date'] ?? ''), 0, 4);
            $mesmoNome = in_array($k, $nomes, true);
            $anoOk = !$ano || !$a || abs($a - $ano) <= 1;
            $parecido = $k !== '' && ($nomes[0] !== '' && (str_starts_with($nomes[0], $k) || str_starts_with($k, $nomes[0])));
            $p = ($mesmoNome ? 4 : ($parecido ? 1 : 0)) + ($anoOk && $ano && $a ? 2 : 0) - $i * 0.1;
            if (!$mesmoNome && !($parecido && $anoOk && $ano)) {
                continue;
            }
            if (!$anoOk && $tipo === 'm') {
                continue;                       // filme com outro ano é outro filme (refilmagem)
            }
            if ($p > $pontos) {
                $pontos = $p;
                $melhor = (int) $r['id'];
            }
        }
        return $melhor;
    }

    /**
     * Detalhes TMDB → colunas e `detalhes` enxuto do título (puro, testado).
     * @return array{tmdb:int, imdb:?string, poster:?string, fundo:?string, sinopse:?string, generos:list<int>, ano:int,
     *               colecao:?array, detalhes:array, pessoas:list<array>}
     */
    public static function enxuto(array $d, string $tipo): array
    {
        $tv = $tipo === 's';
        $credits = $d['credits'] ?? [];
        $kw = $d['keywords']['keywords'] ?? $d['keywords']['results'] ?? [];
        $data = (string) ($tv ? ($d['first_air_date'] ?? '') : ($d['release_date'] ?? ''));
        $elenco = [];
        $pessoas = [];
        foreach (array_slice($credits['cast'] ?? [], 0, 15) as $i => $c) {
            if (empty($c['id']) || empty($c['name'])) {
                continue;
            }
            $elenco[] = ['tmdb' => (int) $c['id'], 'nome' => (string) $c['name'], 'personagem' => ($c['character'] ?? '') ?: null, 'foto' => $c['profile_path'] ?? null];
            $pessoas[] = ['id' => (int) $c['id'], 'nome' => (string) $c['name'], 'foto' => $c['profile_path'] ?? null, 'papel' => 'elenco', 'ordem' => $i, 'personagem' => ($c['character'] ?? '') ?: null];
        }
        $diretor = [];
        foreach ($credits['crew'] ?? [] as $c) {
            if (($c['job'] ?? '') === 'Director' && !empty($c['id']) && count($diretor) < 3) {
                $diretor[] = ['tmdb' => (int) $c['id'], 'nome' => (string) $c['name']];
                $pessoas[] = ['id' => (int) $c['id'], 'nome' => (string) $c['name'], 'foto' => $c['profile_path'] ?? null, 'papel' => 'direcao', 'ordem' => count($diretor) - 1, 'personagem' => null];
            }
        }
        $criadores = [];
        foreach ($d['created_by'] ?? [] as $i => $c) {
            if (!empty($c['id'])) {
                $criadores[] = ['tmdb' => (int) $c['id'], 'nome' => (string) $c['name']];
                $pessoas[] = ['id' => (int) $c['id'], 'nome' => (string) $c['name'], 'foto' => $c['profile_path'] ?? null, 'papel' => 'criacao', 'ordem' => $i, 'personagem' => null];
            }
        }
        $ep = function ($e): ?array {
            return is_array($e) && !empty($e['season_number']) ? [
                'data' => $e['air_date'] ?? null, 'temporada' => (int) $e['season_number'], 'episodio' => (int) ($e['episode_number'] ?? 0),
                'nome' => $e['name'] ?? null, 'duracao_min' => isset($e['runtime']) ? (int) $e['runtime'] : null,
            ] : null;
        };
        $duracao = $tv ? ((int) (($d['episode_run_time'] ?? [])[0] ?? 0) ?: (int) ($d['last_episode_to_air']['runtime'] ?? 0)) : (int) ($d['runtime'] ?? 0);
        $col = $d['belongs_to_collection'] ?? null;
        $colecao = is_array($col) && !empty($col['id']) ? ['id' => (int) $col['id'], 'nome' => $col['name'] ?? null] : null;
        $imdb = $d['imdb_id'] ?? $d['external_ids']['imdb_id'] ?? null;
        $detalhes = array_filter([
            'nome_tmdb'     => $d['title'] ?? $d['name'] ?? null,
            'nome_original' => $d['original_title'] ?? $d['original_name'] ?? null,
            'tagline'       => ($d['tagline'] ?? '') ?: null,
            'data'          => $data ?: null,
            'duracao_min'   => $duracao ?: null,
            'temporadas'    => $tv ? (int) ($d['number_of_seasons'] ?? 0) : null,
            'episodios'     => $tv ? (int) ($d['number_of_episodes'] ?? 0) : null,
            'status'        => $d['status'] ?? null,
            'classificacao' => self::classificacao($d, $tv),
            'generos_nomes' => array_values(array_map(fn($g) => (string) $g['name'], $d['genres'] ?? [])),
            'keywords'      => array_values(array_map(fn($k) => (int) $k['id'], array_slice($kw, 0, 20))),
            'trailer'       => self::trailer($d['videos']['results'] ?? []),
            'nota_tmdb'     => isset($d['vote_average']) ? round((float) $d['vote_average'], 1) : null,
            'votos_tmdb'    => isset($d['vote_count']) ? (int) $d['vote_count'] : null,
            'popularidade'  => isset($d['popularity']) ? round((float) $d['popularity'], 2) : null,
            'idioma'        => $d['original_language'] ?? null,
            'elenco'        => $elenco,
            'diretor'       => $diretor,
            'criadores'     => $criadores,
            'colecao'       => $colecao,
            'proximo_ep'    => $tv ? $ep($d['next_episode_to_air'] ?? null) : null,
            'ultimo_ep'     => $tv ? $ep($d['last_episode_to_air'] ?? null) : null,
        ], fn($x) => $x !== null && $x !== [] && $x !== '');
        return [
            'tmdb'     => (int) $d['id'],
            'imdb'     => is_string($imdb) && preg_match('/^tt\d+$/', $imdb) ? $imdb : null,
            'poster'   => $d['poster_path'] ?? null,
            'fundo'    => $d['backdrop_path'] ?? null,
            'sinopse'  => ($d['overview'] ?? '') ?: null,
            'generos'  => array_values(array_map(fn($g) => (int) $g['id'], $d['genres'] ?? [])),
            'ano'      => (int) substr($data, 0, 4),
            'colecao'  => $colecao,
            'detalhes' => $detalhes,
            'pessoas'  => $pessoas,
        ];
    }

    /** Classificação indicativa do Brasil ("L", "10", "16"...). */
    private static function classificacao(array $d, bool $tv): ?string
    {
        if ($tv) {
            foreach ($d['content_ratings']['results'] ?? [] as $r) {
                if (($r['iso_3166_1'] ?? '') === 'BR' && ($r['rating'] ?? '') !== '') {
                    return (string) $r['rating'];
                }
            }
            return null;
        }
        foreach ($d['release_dates']['results'] ?? [] as $r) {
            if (($r['iso_3166_1'] ?? '') !== 'BR') {
                continue;
            }
            $datas = $r['release_dates'] ?? [];
            usort($datas, fn($a, $b) => (in_array($a['type'] ?? 0, [3, 4], true) ? 0 : 1) <=> (in_array($b['type'] ?? 0, [3, 4], true) ? 0 : 1));
            foreach ($datas as $x) {
                if (($x['certification'] ?? '') !== '') {
                    return (string) $x['certification'];
                }
            }
        }
        return null;
    }

    /** Trailer do YouTube: trailer em português, trailer em inglês, teaser pt, teaser en (oficial antes). */
    public static function trailer(array $videos): ?string
    {
        return self::escolherTrailer($videos)['key'] ?? null;
    }

    /**
     * Melhor trailer e o idioma dele (puro). Português dublado vence (pt-BR antes de pt-PT); trailer antes de teaser;
     * oficial antes. 09/11: a TV liga a legenda pt-BR quando o idioma não é pt.
     * @return array{key:string, idioma:string}|null
     */
    public static function escolherTrailer(array $videos): ?array
    {
        $melhor = null;
        $pontos = -1;
        foreach ($videos as $v) {
            if (($v['site'] ?? '') !== 'YouTube' || empty($v['key']) || !in_array($v['type'] ?? '', ['Trailer', 'Teaser'], true)) {
                continue;
            }
            $pt = ($v['iso_639_1'] ?? '') === 'pt';
            $p = ($pt ? 8 : 0) + ($pt && ($v['iso_3166_1'] ?? '') === 'BR' ? 2 : 0) + (($v['type'] === 'Trailer') ? 4 : 0) + (!empty($v['official']) ? 1 : 0);
            if ($p > $pontos) {
                $pontos = $p;
                $melhor = ['key' => (string) $v['key'], 'idioma' => (string) ($v['iso_639_1'] ?? '')];
            }
        }
        return $melhor;
    }

    /** Trailer do título com o idioma (vídeos pt+en do TMDB, guardados 30 dias). null = sem trailer ou TMDB fora. */
    public static function trailerInfo(string $tipo, ?int $tmdb): ?array
    {
        if (!$tmdb) {
            return null;
        }
        try {
            $r = self::cache(($tipo === 's' ? 'tv/' : 'movie/') . $tmdb . '/videos?include_video_language=pt,en', 30 * 86400);
        } catch (\Throwable $e) {
            return null;
        }
        return self::escolherTrailer($r['results'] ?? []);
    }
}
