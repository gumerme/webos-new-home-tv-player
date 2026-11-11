<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;
use RuntimeException;

/**
 * JustWatch (GraphQL não oficial, Brasil): Top 10 por plataforma, notas (IMDb, Rotten, "gostaram"),
 * títulos parecidos e "em alta no Brasil" para o recomendador. Porte do jwPost/jwInfo/jwFields/jwQuality
 * da v10. Quando falha, fica 30 min sem insistir e o resto segue só com o TMDB.
 */
final class JustWatch
{
    private const URL = 'https://apis.justwatch.com/graphql';
    private const LOTE = 12;

    /** Gêneros do JustWatch → ids TMDB (filmes; séries usam os gêneros de TV do TMDB). */
    public const GENEROS = [
        'm' => ['act' => 28, 'ani' => 16, 'cmy' => 35, 'crm' => 80, 'doc' => 99, 'drm' => 18, 'fml' => 10751, 'fnt' => 14, 'hrr' => 27,
                'hst' => 36, 'msc' => 10402, 'rma' => 10749, 'scf' => 878, 'trl' => 53, 'war' => 10752, 'wsn' => 37],
        's' => ['act' => 10759, 'ani' => 16, 'cmy' => 35, 'crm' => 80, 'doc' => 99, 'drm' => 18, 'fml' => 10751, 'fnt' => 10765, 'hrr' => 9648,
                'rly' => 10764, 'scf' => 10765, 'trl' => 9648, 'war' => 10768, 'wsn' => 37],
    ];

    /** Campos de uma entrada de lista (JW_LIST da v10 + notas). */
    private const CAMPOS_LISTA = 'id objectType content(country:BR,language:"pt"){title originalTitle originalReleaseYear posterUrl'
        . ' externalIds{tmdbId imdbId} genres{shortName} scoring{imdbScore imdbVotes tomatoMeter}'
        . ' interactions{likelistAdditions dislikelistAdditions}}';

    private static int $foraAte = 0;

    public static function post(string $consulta, int $timeout = 20): array
    {
        if (time() < self::$foraAte) {
            throw new RuntimeException('JustWatch falhou há pouco; nova tentativa depois');
        }
        try {
            $r = Http::postJson(self::URL, ['query' => $consulta], ['Origin' => 'https://www.justwatch.com'], $timeout);
        } catch (\Throwable $e) {
            self::$foraAte = time() + 1800;
            throw $e;
        }
        if (!is_array($r) || !isset($r['data']) || !is_array($r['data'])) {
            self::$foraAte = time() + 1800;
            $erro = is_array($r) ? ($r['errors'][0]['message'] ?? '') : '';
            throw new RuntimeException('JustWatch sem dados' . ($erro !== '' ? ": $erro" : ''));
        }
        return $r['data'];
    }

    // ------------------------------------------------------------ Top 10

    /** Consulta de Top 10 (filmes e séries) das plataformas: um alias por plataforma e tipo. */
    public static function consultaTop10(array $pacotes, int $n = 10): string
    {
        $partes = [];
        foreach ($pacotes as $codigo => $pacote) {
            foreach (['m' => 'MOVIE', 's' => 'SHOW'] as $t => $obj) {
                $partes[] = self::alias($codigo, $t) . ':popularTitles(country:BR,first:' . $n . ',sortBy:POPULAR,filter:{packages:['
                    . json_encode((string) $pacote) . '],objectTypes:[' . $obj . ']}){edges{node{' . self::CAMPOS_LISTA . '}}}';
            }
        }
        return 'query{' . implode(' ', $partes) . '}';
    }

    public static function alias(string $codigo, string $tipo): string
    {
        return 'p_' . preg_replace('/\W/', '_', $codigo) . '_' . $tipo;
    }

    /**
     * Resposta → entradas (pura, testada).
     * @return array<string, array{m:list<array>, s:list<array>}> codigo => tipo => [{posicao, jw_id, tmdb, imdb, nome, nome_original, ano, poster, notas}]
     */
    public static function lerTop10(array $data, array $codigos): array
    {
        $saida = [];
        foreach ($codigos as $codigo) {
            foreach (['m', 's'] as $t) {
                $lista = [];
                foreach (($data[self::alias($codigo, $t)]['edges'] ?? []) as $i => $e) {
                    $x = self::lerNo($e['node'] ?? []);
                    if ($x) {
                        $lista[] = ['posicao' => count($lista) + 1] + $x;
                    }
                }
                $saida[$codigo][$t] = $lista;
            }
        }
        return $saida;
    }

    /** Um nó de lista do JustWatch → formato do worker (null sem título). */
    public static function lerNo(array $n): ?array
    {
        $c = $n['content'] ?? [];
        if (empty($c['title'])) {
            return null;
        }
        $tipo = ($n['objectType'] ?? '') === 'SHOW' ? 's' : 'm';
        $generos = [];
        foreach ($c['genres'] ?? [] as $g) {
            $id = self::GENEROS[$tipo][$g['shortName'] ?? ''] ?? null;
            if ($id && !in_array($id, $generos, true)) {
                $generos[] = $id;
            }
        }
        return [
            'tipo'          => $tipo,
            'jw_id'         => isset($n['id']) ? (string) $n['id'] : null,
            'tmdb'          => (int) ($c['externalIds']['tmdbId'] ?? 0) ?: null,
            'imdb'          => ($c['externalIds']['imdbId'] ?? null) ?: null,
            'nome'          => (string) $c['title'],
            'nome_original' => ($c['originalTitle'] ?? null) ?: null,
            'ano'           => (int) ($c['originalReleaseYear'] ?? 0) ?: null,
            'poster'        => self::poster($c['posterUrl'] ?? null),
            'generos'       => $generos,
            'notas'         => self::notas($n),
        ];
    }

    /** Pôster do JustWatch em 332 px (o caminho vem como modelo com {profile}/{format}). */
    public static function poster(?string $p): ?string
    {
        return $p ? 'https://images.justwatch.com' . str_replace(['{profile}', '{format}'], ['s332', 'jpg'], $p) : null;
    }

    /** Notas no formato de play.titulo.jw: {imdb, imdb_votos, rotten, gostaram, curtidas, descurtidas, id}. */
    public static function notas(array $n): array
    {
        $c = $n['content'] ?? [];
        $sc = $c['scoring'] ?? [];
        $it = $c['interactions'] ?? [];
        $gosta = (int) ($it['likelistAdditions'] ?? 0);
        $nao = (int) ($it['dislikelistAdditions'] ?? 0);
        // gêneros do JustWatch (shortName): classificação das categorias e fileiras de gênero (Generos)
        $g = [];
        foreach ($c['genres'] ?? [] as $x) {
            $s = (string) ($x['shortName'] ?? '');
            if ($s !== '' && !in_array($s, $g, true)) {
                $g[] = $s;
            }
        }
        return ['g' => $g] + array_filter([
            'id'          => isset($n['id']) ? (string) $n['id'] : null,
            'imdb'        => isset($sc['imdbScore']) ? round((float) $sc['imdbScore'], 1) : null,
            'imdb_votos'  => isset($sc['imdbVotes']) ? (int) $sc['imdbVotes'] : null,
            'rotten'      => isset($sc['tomatoMeter']) ? (int) $sc['tomatoMeter'] : null,
            'gostaram'    => $gosta + $nao >= 30 ? (int) round(100 * $gosta / ($gosta + $nao)) : null,
            'curtidas'    => $gosta ?: null,
            'descurtidas' => $nao ?: null,
        ], fn($x) => $x !== null);
    }

    /**
     * Qualidade pelo IMDb (muito mais votos que o TMDB) e Rotten, vezes como os usuários do JustWatch
     * receberam (jwQuality da v10). 0 = JustWatch não conhece.
     */
    public static function qualidade(?array $jw, bool $blockbuster): float
    {
        if (!$jw || empty($jw['imdb'])) {
            return 0.0;
        }
        $votos = (int) ($jw['imdb_votos'] ?? 0);
        $nota = !empty($jw['rotten']) ? 0.75 * $jw['imdb'] + 0.25 * ($jw['rotten'] / 10) : (float) $jw['imdb'];
        $q = 0.75 + 0.5 * ($nota / 10) * min(1, log($votos + 1) / log(50000));
        if ($blockbuster) {
            $q *= 1 + 0.3 * min(1, log($votos + 1) / log(1000000));
        }
        $n = (int) ($jw['curtidas'] ?? 0) + (int) ($jw['descurtidas'] ?? 0);
        if ($n >= 30) {
            $q *= 0.85 + 0.3 * (($jw['curtidas'] ?? 0) / $n);
        }
        return $q;
    }

    // ------------------------------------------------------------ por título (notas e parecidos)

    /**
     * Notas (e, se pedido, parecidos) de vários títulos, 12 por consulta (jwFlush da v10). Cada um é
     * achado pelo nome pt-BR/original e confirmado pelo id TMDB (sem TMDB: nome + ano ±1).
     * @param list<array{chave:string, tipo:string, tmdb:?int, nome:string, original:?string, ano:?int}> $pedidos
     * @return array<string, ?array> chave => {notas, parecidos?} | null (não achou)
     */
    public static function info(array $pedidos, bool $parecidos = false): array
    {
        $saida = [];
        foreach (array_chunk($pedidos, self::LOTE) as $lote) {
            $partes = [];
            $aliases = [];
            foreach ($lote as $i => $p) {
                $nomes = [$p['nome']];
                if (!empty($p['original']) && Texto::norm($p['original']) !== Texto::norm($p['nome'])) {
                    $nomes[] = $p['original'];
                }
                foreach ($nomes as $k => $nome) {
                    $a = "a{$i}_$k";
                    $aliases[$i][] = $a;
                    $partes[] = $a . ':popularTitles(country:BR,first:5,filter:{searchQuery:' . json_encode((string) $nome, JSON_UNESCAPED_UNICODE)
                        . ',objectTypes:[' . ($p['tipo'] === 's' ? 'SHOW' : 'MOVIE') . ']}){edges{node{' . self::CAMPOS_LISTA
                        . ($parecidos ? ' similarTitlesV2(country:BR,first:15){edges{node{' . self::CAMPOS_LISTA . '}}}' : '') . '}}}';
                }
            }
            $data = self::post('query{' . implode(' ', $partes) . '}', 25);
            foreach ($lote as $i => $p) {
                $no = null;
                foreach ($aliases[$i] as $a) {
                    $no ??= self::escolher($p, $data[$a]['edges'] ?? []);
                }
                if (!$no) {
                    $saida[$p['chave']] = null;
                    continue;
                }
                $v = ['notas' => self::notas($no)];
                if ($parecidos) {
                    $v['parecidos'] = array_values(array_filter(array_map(fn($e) => self::lerNo($e['node'] ?? []), $no['similarTitlesV2']['edges'] ?? [])));
                }
                $saida[$p['chave']] = $v;
            }
        }
        return $saida;
    }

    /** O resultado da busca que é este título (jwPick da v10). */
    public static function escolher(array $p, array $edges): ?array
    {
        foreach ($edges as $e) {
            $n = $e['node'] ?? [];
            $c = $n['content'] ?? [];
            if (!empty($p['tmdb'])) {
                if ((string) ($c['externalIds']['tmdbId'] ?? '') === (string) $p['tmdb']) {
                    return $n;
                }
                continue;
            }
            $nomes = [Texto::norm($c['title'] ?? ''), Texto::norm($c['originalTitle'] ?? '')];
            $mesmo = in_array(Texto::norm($p['nome']), $nomes, true) || (!empty($p['original']) && in_array(Texto::norm($p['original']), $nomes, true));
            $a = (int) ($c['originalReleaseYear'] ?? 0);
            if ($mesmo && (empty($p['ano']) || !$a || abs($a - (int) $p['ano']) <= 1)) {
                return $n;
            }
        }
        return null;
    }

    /**
     * Notas dos títulos (play.titulo.jw), renovadas a cada semana; guarda também os parecidos
     * (play.cache 'jw:sim:<id>') quando pedidos. Devolve titulo_id => {notas, parecidos?}.
     */
    public static function notasDosTitulos(array $ids, bool $parecidos = false): array
    {
        if (!$ids) {
            return [];
        }
        $pdo = Db::pdo();
        $st = $pdo->prepare("select id, tipo, nome, ano, tmdb_id, jw, jw_em > now() - interval '7 days' as fresco,
                                    detalhes->>'nome_tmdb' as nome_tmdb, detalhes->>'nome_original' as original
                               from play.titulo where id = any(cast(? as bigint[]))");
        $st->execute([Banco::ints($ids)]);
        $saida = [];
        $pedidos = [];
        foreach ($st->fetchAll() as $t) {
            $id = (int) $t['id'];
            $sim = $parecidos ? Banco::cache("jw:sim:$id", 7 * 86400) : null;
            if (Banco::bool($t['fresco']) && (!$parecidos || $sim !== null)) {
                $saida[$id] = ['notas' => json_decode((string) $t['jw'], true) ?: []] + ($parecidos ? ['parecidos' => $sim] : []);
                continue;
            }
            $pedidos[] = ['chave' => (string) $id, 'tipo' => $t['tipo'], 'tmdb' => $t['tmdb_id'] !== null ? (int) $t['tmdb_id'] : null,
                          'nome' => (string) ($t['nome_tmdb'] ?: $t['nome']), 'original' => $t['original'], 'ano' => $t['ano'] !== null ? (int) $t['ano'] : null];
        }
        if (!$pedidos) {
            return $saida;
        }
        try {
            $r = self::info($pedidos, $parecidos);
        } catch (\Throwable $e) {
            Log::info('JustWatch (notas): ' . $e->getMessage());
            return $saida;
        }
        // não achou: guarda {} mas mantém os gêneros já conhecidos (ou g:[] = vale o TMDB)
        $up = $pdo->prepare("update play.titulo set jw = case when jsonb_exists(cast(? as jsonb), 'g') then cast(? as jsonb)
                                                             else jsonb_build_object('g', coalesce(jw->'g', '[]'::jsonb)) end,
                                                    jw_em = now() where id = ?");
        foreach ($r as $chave => $v) {
            $id = (int) $chave;
            $j = Banco::json(($v['notas'] ?? null) ?: new \stdClass());
            $up->execute([$j, $j, $id]);
            if ($parecidos) {
                Banco::guardar("jw:sim:$id", $v['parecidos'] ?? []);
            }
            $saida[$id] = $v ?? ['notas' => []];
        }
        return $saida;
    }

    /** "Em alta no Brasil" (todas as plataformas), 40 de cada: all/m/s. Guardado 3 h. */
    public static function emAltaBrasil(): array
    {
        return Banco::lembrar('jw:brhot', 3 * 3600, function () {
            $f = '{edges{node{' . self::CAMPOS_LISTA . '}}}';
            $data = self::post('query{all:popularTitles(country:BR,first:40,sortBy:POPULAR)' . $f
                . ' m:popularTitles(country:BR,first:40,sortBy:POPULAR,filter:{objectTypes:[MOVIE]})' . $f
                . ' s:popularTitles(country:BR,first:40,sortBy:POPULAR,filter:{objectTypes:[SHOW]})' . $f . '}');
            $listas = [];
            foreach (['all', 'm', 's'] as $k) {
                $listas[$k] = array_values(array_filter(array_map(fn($e) => self::lerNo($e['node'] ?? []), $data[$k]['edges'] ?? [])));
            }
            return $listas['all'] ? $listas : null;
        }) ?? ['all' => [], 'm' => [], 's' => []];
    }
}
