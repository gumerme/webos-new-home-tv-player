<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Tarefa "top10" (de hora em hora): Top 10 de filmes e de séries do Brasil em cada plataforma ativa
 * (JustWatch). Cada rodada fica em play.top10 com o mesmo `coletado_em` (histórico: as setas comparam
 * com a última coleta de ontem). Casa com a lista pelo tmdb, senão pelo nome+ano (e pelo nome pt-BR
 * do TMDB, porque o "pt" do JustWatch às vezes é o de Portugal). Os casados ganham as notas do JustWatch.
 */
final class Top10
{
    public static function tarefa(): array
    {
        $pdo = Db::pdo();
        $plats = $pdo->query('select codigo, jw_package from play.plataforma where ativa order by ordem')->fetchAll(\PDO::FETCH_KEY_PAIR);
        if (!$plats) {
            return ['msg' => 'nenhuma plataforma ativa'];
        }
        $listas = JustWatch::lerTop10(JustWatch::post(JustWatch::consultaTop10($plats)), array_keys($plats));
        $indice = Indice::compartilhado();
        $rodada = (string) $pdo->query('select now()')->fetchColumn();
        $linhas = [];
        $notas = [];
        $tmdbNovo = [];
        $resumo = [];
        foreach ($listas as $codigo => $porTipo) {
            $r = [];
            foreach ($porTipo as $tipo => $itens) {
                $casados = 0;
                foreach ($itens as $e) {
                    $id = self::casar($indice, $tipo, $e);
                    if ($id) {
                        $casados++;
                        $notas[$id] = $e['notas'];
                        if ($e['tmdb'] && !$indice->tmdbDe($id)) {
                            $tmdbNovo[$id] = $e['tmdb'];
                            $indice->lembrarTmdb($tipo, $e['tmdb'], $id);
                        }
                    }
                    $linhas[] = ['t' => $tipo, 'p' => $codigo, 'pos' => $e['posicao'], 'jw' => $e['jw_id'], 'tmdb' => $e['tmdb'],
                                 'nome' => $e['nome'], 'ano' => $e['ano'], 'poster' => $e['poster'], 'id' => $id];
                }
                $r[] = "$casados/" . count($itens) . " $tipo";
            }
            $resumo[] = "$codigo " . implode(' ', $r);
        }
        if (!$linhas) {
            return ['msg' => 'JustWatch não devolveu nenhum título'];
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                insert into play.top10 (coletado_em, tipo, plataforma, posicao, jw_id, tmdb_id, nome, ano, poster, titulo_id)
                select cast(? as timestamptz), x.t, x.p, x.pos, x.jw, x.tmdb, x.nome, x.ano, x.poster, x.id
                  from jsonb_to_recordset(cast(? as jsonb)) as x(t text, p text, pos int, jw text, tmdb int, nome text, ano int, poster text, id bigint)
                on conflict do nothing")->execute([$rodada, Banco::json($linhas)]);
            if ($notas) {
                $pdo->prepare("update play.titulo t set jw = cast(x.n as jsonb), jw_em = now()
                                 from jsonb_to_recordset(cast(? as jsonb)) as x(id bigint, n text) where t.id = x.id")
                    ->execute([Banco::json(array_map(fn($id, $n) => ['id' => $id, 'n' => Banco::json($n ?: new \stdClass())], array_keys($notas), $notas))]);
            }
            if ($tmdbNovo) {
                // o JustWatch deu o tmdb de um título que só tinha nome: detalhes de novo pelo id certo
                $pdo->prepare("update play.titulo t set tmdb_id = x.tmdb, detalhes_em = null
                                 from jsonb_to_recordset(cast(? as jsonb)) as x(id bigint, tmdb int) where t.id = x.id and t.tmdb_id is null")
                    ->execute([Banco::json(array_map(fn($id, $t) => ['id' => $id, 'tmdb' => $t], array_keys($tmdbNovo), $tmdbNovo))]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        try {
            $resumo[] = self::topBrasil($indice);
        } catch (\PDOException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resumo[] = 'Brasil: ' . Http::mascarar($e->getMessage());
        }
        Sinais::atualizado('top10');
        return ['msg' => implode('; ', $resumo)];
    }

    /**
     * "Top Brasil": ranking geral do JustWatch Brasil (todas as plataformas, filmes e séries juntos), 20
     * primeiros, casado com a lista e guardado pronto em play.cache 'top:brasil' (o Início só lê).
     */
    public static function topBrasil(Indice $indice): string
    {
        $itens = [];
        foreach (array_slice(JustWatch::emAltaBrasil()['all'] ?? [], 0, 20) as $i => $e) {
            $itens[] = ['posicao' => $i + 1, 'tipo' => $e['tipo'], 'id' => self::casar($indice, $e['tipo'], $e),
                        'tmdb' => $e['tmdb'], 'nome' => $e['nome'], 'ano' => $e['ano'], 'poster' => $e['poster']];
        }
        if (!$itens) {
            return 'Brasil: JustWatch vazio';
        }
        Banco::guardar('top:brasil', ['em' => date('c'), 'itens' => $itens]);
        return 'Brasil ' . count(array_filter(array_column($itens, 'id'))) . '/' . count($itens);
    }

    /** Entrada do JustWatch → título da lista (ou null: a TV mostra apagado com "Avisar"). */
    public static function casar(Indice $indice, string $tipo, array $e): ?int
    {
        if ($id = $indice->porTmdb($tipo, $e['tmdb'])) {
            return $id;
        }
        $id = $indice->casarTmdb(['id' => $e['tmdb'], 'title' => $e['nome'], 'original_title' => $e['nome_original'],
                                  'release_date' => $e['ano'] ? $e['ano'] . '-01-01' : ''], $tipo);
        if ($id === null && $e['tmdb'] && Tmdb::configurado()) {
            // nome pt-BR do TMDB (o "pt" do JustWatch pode ser o de Portugal)
            try {
                $d = Tmdb::cache(($tipo === 's' ? 'tv/' : 'movie/') . $e['tmdb'] . '?language=pt-BR', 30 * 86400,
                    fn($d) => array_intersect_key($d, array_flip(['id', 'title', 'name', 'original_title', 'original_name'])));
                $nome = $d['title'] ?? $d['name'] ?? '';
                if ($nome !== '') {
                    $id = $indice->casarTmdb(['id' => $e['tmdb'], 'title' => $nome, 'release_date' => $e['ano'] ? $e['ano'] . '-01-01' : ''], $tipo);
                }
            } catch (\Throwable) {
                // sem TMDB agora: fica fora da lista nesta rodada
            }
        }
        return $id;
    }
}
