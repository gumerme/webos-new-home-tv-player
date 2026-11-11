<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Tarefa "recomendacoes": porte do recommend() da v10, por perfil, gravado pronto em play.recomendacao.
 *
 *  1. Sinais: o que o perfil assistiu (meia-vida de 14 dias; terminou > metade > começou; largou nos
 *     primeiros minutos conta contra), "Gostei"/"Não gostei", a Minha lista (like fraco) e o gosto
 *     semeado (preferência "gosto": o PLAY_TASTE da v10 no perfil Casa).
 *  2. Perfil de gosto: gêneros, palavras-chave, pessoas (elenco, direção/criação) e década dos sinais.
 *  3. Candidatos: recomendações + parecidos do TMDB de cada sinal, parecidos do JustWatch, descoberta
 *     nos gêneros do perfil, filmes das pessoas do gosto, época (Natal), em alta na semana e no Brasil.
 *  4. Nota = força dos sinais que sugeriram × corroboração × gosto × qualidade (IMDb/Rotten/JustWatch
 *     nos 90 primeiros) × recência × em alta no Brasil; só títulos da lista, ativos, link não quebrado,
 *     nunca começados nem avaliados.
 *  5. Variedade: gênero e saga repetidos pesam menos; a cada 7 vagas, uma exploração bem avaliada fora
 *     dos gêneros de sempre.
 * Recalcula a cada 10 min se histórico/lista/notas mudaram; senão uma vez por noite. "Gostei"/"Não gostei"
 * (POST nota) pede o recálculo na hora (Tarefas::forcar; roda até no meio de uma tarefa longa, ver ceder).
 */
final class Recomendador
{
    private const MEIA_VIDA_DIAS = 14;
    private const VERSAO = 'r7';      // r2: gêneros JustWatch; r3: gênero principal pesa mais; r4: listas por tipo; r5: Netflix; r6: colaborativo (MovieLens/Trakt)
    /** Época padrão quando o gosto do perfil não traz outra (Natal, como a v10). */
    public const EPOCA_PADRAO = ['keyword' => 207317, 'titulo' => 'Filmes de Natal para esta época', 'de' => '11-01', 'ate' => '01-06', 'categoria_painel' => 'natal'];

    public static function tarefa(): array
    {
        $pdo = Db::pdo();
        $perfis = $pdo->query('select p.id, p.nome, r.chave, extract(epoch from r.gerado_em)::bigint as gerado
                                 from play.perfil p left join play.recomendacao r on r.perfil_id = p.id order by p.id')->fetchAll();
        $agora = time();
        $hora = (int) date('G', $agora);
        $feitos = [];
        foreach ($perfis as $p) {
            $id = (int) $p['id'];
            $chave = self::chave($id, $agora);
            $idade = $p['gerado'] !== null ? $agora - (int) $p['gerado'] : PHP_INT_MAX;
            $noite = $hora >= 3 && $hora < 6 && $idade > 20 * 3600;
            if ($p['chave'] === $chave && !$noite && $idade < 30 * 3600) {
                continue;
            }
            $ini = microtime(true);
            $dados = self::gerar($id, $agora);
            $pdo->prepare('insert into play.recomendacao (perfil_id, gerado_em, dados, chave) values (?, now(), cast(? as jsonb), ?)
                           on conflict (perfil_id) do update set gerado_em = now(), dados = excluded.dados, chave = excluded.chave')
                ->execute([$id, Banco::json($dados), $chave]);
            // trailers dos destaques já em português (busca no YouTube fica no worker, não no pedido da TV)
            try {
                Trailer::prepararDestaques(array_map('intval', (array) ($dados['destaque'] ?? [])));
            } catch (\PDOException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::info('recomendações: trailers dos destaques: ' . $e->getMessage());
            }
            Sinais::atualizado('recomendacoes', $id);
            $feitos[] = sprintf('%s (%d recomendados, %.0f s)', $p['nome'], count($dados['recomendados']), microtime(true) - $ini);
            if (Tarefas::ceder()) {
                break;
            }
        }
        return ['msg' => $feitos ? 'recalculado: ' . implode('; ', $feitos) : 'nada mudou'];
    }

    /** Impressão digital do que alimenta a recomendação do perfil (historyKey da v10). */
    public static function chave(int $perfil, int $agora): string
    {
        $st = Db::pdo()->prepare("
            select md5(concat_ws('|',
                (select string_agg(h.titulo_id || ':' || coalesce(h.temporada, 0) || ':' || coalesce(h.episodio, 0) || ':'
                                   || (h.assistido_s / 120) || ':' || h.terminado::text, ',' order by h.id)
                   from (select * from play.historico where perfil_id = ? order by id desc limit 80) h),
                (select string_agg(titulo_id::text, ',' order by titulo_id) from play.lista where perfil_id = ?),
                (select string_agg(titulo_id || ':' || valor, ',' order by titulo_id) from play.nota where perfil_id = ?),
                (select string_agg(titulo_id || ':' || (posicao_s / 600) || ':' || terminado::text, ',' order by titulo_id)
                   from play.progresso where perfil_id = ?),
                (select valor::text from play.preferencia where perfil_id = ? and chave = 'gosto'),
                (select valor->>'em' from play.preferencia where perfil_id = ? and chave = 'netflix')))");
        $st->execute([$perfil, $perfil, $perfil, $perfil, $perfil, $perfil]);
        $gosto = self::gosto($perfil);
        return $st->fetchColumn() . (self::naEpoca($gosto['epoca'] ?? self::EPOCA_PADRAO, $agora) ? '|epoca' : '') . '|' . self::VERSAO
            . '|' . md5(Colaborativo::versao());
    }

    /** Preferência "gosto" do perfil (semente; ver sql/008). */
    public static function gosto(int $perfil): array
    {
        $st = Db::pdo()->prepare("select valor from play.preferencia where perfil_id = ? and chave = 'gosto'");
        $st->execute([$perfil]);
        $v = $st->fetchColumn();
        return $v ? (json_decode((string) $v, true) ?: []) : [];
    }

    public static function naEpoca(?array $e, int $agora): bool
    {
        if (!$e || empty($e['de']) || empty($e['ate'])) {
            return false;
        }
        $md = date('m-d', $agora);
        return $e['de'] <= $e['ate'] ? ($md >= $e['de'] && $md <= $e['ate']) : ($md >= $e['de'] || $md <= $e['ate']);
    }

    /** Peso de um item do histórico (signalWeight da v10). h = {t, f, eps, tipo}. */
    public static function pesoSinal(array $h, int $agora): float
    {
        $idade = max(0, $agora - (int) $h['t']) / 86400;
        $recencia = 0.5 ** ($idade / self::MEIA_VIDA_DIAS);
        $f = (float) ($h['f'] ?? 0);
        $eps = (int) ($h['eps'] ?? 0);
        if (($h['tipo'] ?? 'm') === 's' && $eps >= 2) {
            $engaj = min(1.6, 0.9 + 0.12 * $eps);       // continuou assistindo: gostou
        } elseif ($f < 0.06) {
            $engaj = -0.7;                              // largou logo
        } elseif ($f < 0.35) {
            $engaj = 0.45;
        } elseif ($f < 0.85) {
            $engaj = 0.85;
        } else {
            $engaj = 1.15;                              // até o fim
        }
        return $recencia * $engaj;
    }

    /** Recentes pesam mais (até 1,3 no ano; some em 8 anos; um pouco menos depois de 20). */
    public static function recencia(int $ano, int $anoAtual): float
    {
        if (!$ano) {
            return 1.0;
        }
        $idade = max(0, $anoAtual - $ano);
        return 1 + 0.3 * max(0, 1 - $idade / 8) - ($idade > 20 ? 0.08 : 0);
    }

    // ------------------------------------------------------------ geração

    /** @return array{destaque:list<int>, recomendados:list<int>, porque:list<array>, generos:list<array>, epoca:?array, gosto:array} */
    public static function gerar(int $perfil, int $agora): array
    {
        $pdo = Db::pdo();
        $indice = Indice::compartilhado();
        $gosto = self::gosto($perfil);
        $epoca = $gosto['epoca'] ?? self::EPOCA_PADRAO;
        $naEpoca = self::naEpoca($epoca, $agora);
        $blockbuster = !empty($gosto['blockbuster']);

        // ---- 1. sinais
        $st = $pdo->prepare("
            select h.titulo_id, t.tipo, t.nome,
                   extract(epoch from max(coalesce(h.fim, h.inicio)))::bigint as t,
                   max(case when coalesce(h.duracao_s, 0) > 0 then least(1.0, h.assistido_s::float / h.duracao_s)
                            when h.terminado then 1.0 else 0.0 end) as f,
                   count(distinct coalesce(h.temporada, 0) * 10000 + h.episodio) filter (where h.episodio is not null) as eps
              from play.historico h join play.titulo t on t.id = h.titulo_id
             where h.perfil_id = ?
             group by h.titulo_id, t.tipo, t.nome
             order by 4 desc limit 60");
        $st->execute([$perfil]);
        $hist = $st->fetchAll();
        $comecados = [];
        foreach ($hist as $h) {
            $comecados[(int) $h['titulo_id']] = true;
        }
        // progresso sem histórico (importado da v10, por exemplo)
        $st = $pdo->prepare("select p.titulo_id, t.tipo, t.nome, extract(epoch from p.atualizado_em)::bigint as t,
                                    case when p.terminado then 1.0 when p.duracao_s > 0 then least(1.0, p.posicao_s::float / p.duracao_s) else 0.0 end as f,
                                    0 as eps
                               from play.progresso p join play.titulo t on t.id = p.titulo_id where p.perfil_id = ?");
        $st->execute([$perfil]);
        foreach ($st->fetchAll() as $p) {
            if (!isset($comecados[(int) $p['titulo_id']])) {
                $hist[] = $p;
                $comecados[(int) $p['titulo_id']] = true;
            }
        }
        usort($hist, fn($a, $b) => (int) $b['t'] <=> (int) $a['t']);
        $st = $pdo->prepare('select titulo_id, valor from play.nota where perfil_id = ?');
        $st->execute([$perfil]);
        $notas = [];
        foreach ($st->fetchAll() as $n) {
            $notas[(int) $n['titulo_id']] = (int) $n['valor'];
            $comecados[(int) $n['titulo_id']] = true;
        }
        $st = $pdo->prepare('select titulo_id from play.lista where perfil_id = ? order by adicionado_em desc limit 20');
        $st->execute([$perfil]);
        $lista = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
        $semente = [];
        foreach ($gosto['titulos'] ?? [] as $g) {
            $id = self::tituloDaSemente($indice, $g);
            if ($id) {
                $semente[$id] = (float) ($g['w'] ?? 1);
                $comecados[$id] = true;
            }
        }
        // Netflix (Atividade de visualização importada): o que está na lista conta como visto; o resto é sinal externo
        $externos = self::sinaisNetflix($perfil, $indice, $comecados, $agora);
        $sinais = self::sinais($hist, $notas, $lista, $semente, $comecados, $agora, [], $externos);
        $quebrados = array_flip(array_map('intval', $pdo->query('select id from play.titulo where ativo and link_ok = false')->fetchAll(\PDO::FETCH_COLUMN)));
        $tmdbComecado = [];
        foreach (array_keys($comecados) as $id) {
            if ($t = $indice->tmdbDe($id)) {
                $tmdbComecado[$indice->tipoDe($id) . $t] = true;
            }
        }
        $elegivel = fn(int $id) => !isset($comecados[$id]) && !isset($quebrados[$id]);

        if (!array_filter($sinais, fn($s) => $s['w'] > 0)) {
            return self::partidaFria($indice, $elegivel, $naEpoca ? $epoca : null, $agora);
        }

        // ---- 2. perfil de gosto (features dos sinais pelos detalhes TMDB do título)
        $feat = self::features(array_values(array_filter(array_column($sinais, 'id'), fn($i) => $i > 0)));
        $feat += self::featuresExternas(array_filter($sinais, fn($s) => $s['id'] < 0));
        $sinais = array_values(array_filter($sinais, fn($s) => isset($feat[$s['id']])));
        foreach ($sinais as &$s) {
            $s['f'] = $feat[$s['id']];
            $s['tmdb'] = $feat[$s['id']]['tmdb'];
            $s['tipo'] = $feat[$s['id']]['tipo'];
            $s['nome'] = $s['nome'] ?: $feat[$s['id']]['nome'];
        }
        unset($s);
        // gosto mais largo com a Netflix: além dos 14 sinais fortes, até 40 obras de lá entram só no perfil
        $usados = array_flip(array_column($sinais, 'id'));
        $extra = array_slice(array_values(array_filter($externos, fn($x) => !isset($usados[$x['id']]) && $x['w'] > 0)), 0, 40);
        $fx = self::features(array_values(array_filter(array_column($extra, 'id'), fn($i) => $i > 0)), 0)
            + self::featuresExternas(array_filter($extra, fn($x) => $x['id'] < 0));
        $extra = array_values(array_filter(array_map(fn($x) => isset($fx[$x['id']]) ? $x + ['f' => $fx[$x['id']]] : null, $extra)));
        $perfilGosto = self::montarPerfil(array_merge($sinais, $extra), $gosto, $naEpoca ? $epoca : null);

        // ---- 3. fontes de candidatos
        $conjuntos = self::fontes($sinais, $perfilGosto, $gosto, $naEpoca ? $epoca : null, $agora);
        $nomesGenero = $conjuntos['nomes'];
        $brPos = $conjuntos['brPos'];
        // filtragem colaborativa (MovieLens/Trakt, calculada pelo casa-rec e pela tarefa trakt): a fonte mais forte;
        // as obras extras da Netflix também puxam vizinhos, com metade do peso e sem "porque você assistiu"
        $extraCf = array_map(fn($x) => ['id' => $x['id'], 'w' => $x['w'], 'tipo' => $x['f']['tipo'], 'tmdb' => $x['f']['tmdb']], $extra);
        $viz = Colaborativo::vizinhos(array_map(fn($s) => [$s['tipo'], (int) $s['tmdb']], array_merge($sinais, $extraCf)));
        if ($viz) {
            $reg = Colaborativo::registros($viz);
            $conjuntos['sets'] = array_merge($conjuntos['sets'], Colaborativo::conjuntos($sinais, $viz, $reg),
                array_map(fn($set) => ['sinal' => null] + $set, Colaborativo::conjuntos($extraCf, $viz, $reg, 0.5)));
        }

        // ---- 4. nota
        $cands = self::pontuar($conjuntos['sets'], $perfilGosto, $blockbuster, $brPos, (int) date('Y', $agora));
        $escolhas = [];
        foreach ($cands as $c) {
            if (isset($tmdbComecado[$c['tipo'] . $c['r']['id']])) {
                continue;
            }
            $id = $indice->casarTmdb($c['r'], $c['tipo']);
            if ($id === null || !$elegivel($id) || isset($escolhas[$id])) {
                continue;
            }
            $c['id'] = $id;
            $escolhas[$id] = $c;
            if (count($escolhas) >= 220) {
                break;
            }
        }
        $escolhas = array_values($escolhas);
        // os 90 primeiros: qualidade do JustWatch; os 45 primeiros: palavras-chave e pessoas do perfil
        $jw = JustWatch::notasDosTitulos(array_column(array_slice($escolhas, 0, 90), 'id'));
        foreach (array_slice(array_keys($escolhas), 0, 90) as $i) {
            $q = JustWatch::qualidade($jw[$escolhas[$i]['id']]['notas'] ?? null, $blockbuster);
            if ($q > 0 && $escolhas[$i]['qualidade'] > 0) {
                $escolhas[$i]['nota'] = $escolhas[$i]['nota'] / $escolhas[$i]['qualidade'] * $q;
            }
        }
        $featCab = self::features(array_column(array_slice($escolhas, 0, 45), 'id'), 25);
        foreach ($escolhas as $i => &$c) {
            $f = $featCab[$c['id']] ?? null;
            if ($i < 45 && $f) {
                $kw = 0.0;
                $ppl = 0.0;
                foreach ($f['keywords'] as $x) {
                    $kw += max(0, $perfilGosto['keywords'][$x] ?? 0);
                }
                foreach ($f['pessoas'] as $x) {
                    $ppl += max(0, $perfilGosto['pessoas'][$x] ?? 0);
                }
                $c['nota'] *= 1 + 0.12 * min(5, $kw) + 0.18 * min(3, $ppl);
            }
            $c['genero'] = $f['generos'][0] ?? ($c['r']['genre_ids'][0] ?? 0);
            $c['colecao'] = $f['colecao'] ?? 0;
        }
        unset($c);
        usort($escolhas, fn($a, $b) => $b['nota'] <=> $a['nota']);

        // ---- 5. variedade e fileiras
        $fim = self::finalizar($escolhas, $perfilGosto, $sinais, $nomesGenero);
        $epocaIds = $naEpoca ? self::fileiraEpoca($indice, $conjuntos['epoca'], $epoca, $elegivel) : [];
        $generosJw = self::fileirasGenero($perfilGosto, $elegivel, $blockbuster, $escolhas, $fim['recomendados'], $agora);
        // Séries/Filmes › Para você: 40 de cada tipo (as 40 gerais podem ser quase só séries ou só filmes)
        $porTipo = [];
        foreach (['m', 's'] as $t) {
            $esc = array_values(array_filter($escolhas, fn($c) => $c['tipo'] === $t));
            $lista = $esc ? self::finalizar($esc, $perfilGosto, $sinais, $nomesGenero)['recomendados'] : [];
            $gen = self::fileirasGenero($perfilGosto, $elegivel, $blockbuster, $escolhas, [], $agora, $t, 6);
            $porTipo[$t] = ['recomendados' => self::completar($lista, $gen, 40), 'generos' => $gen];
        }
        return [
            'destaque'     => self::destaque($fim['recomendados']),
            'recomendados' => $fim['recomendados'],
            'porque'       => $fim['porque'],
            'generos'      => $generosJw ?: array_slice($fim['generos'], 0, 4),
            'por_tipo'     => $porTipo,
            'epoca'        => count($epocaIds) >= 4 ? ['titulo' => $epoca['titulo'] ?? self::EPOCA_PADRAO['titulo'], 'subtitulo' => 'Os mais novos primeiro', 'ids' => array_slice($epocaIds, 0, 40)] : null,
            'gosto'        => self::resumoGosto($perfilGosto, $nomesGenero),
        ];
    }

    /** Título da semente do gosto: pelo tmdb, senão pelo id do painel, senão pelo nome. */
    private static function tituloDaSemente(Indice $indice, array $g): ?int
    {
        $tipo = ($g['tipo'] ?? 'm') === 's' ? 's' : 'm';
        if ($id = $indice->porTmdb($tipo, (int) ($g['tmdb'] ?? 0))) {
            return $id;
        }
        if (!empty($g['painel'])) {
            $st = Db::pdo()->prepare('select v.titulo_id from play.versao v join play.titulo t on t.id = v.titulo_id and t.ativo
                                       where v.tipo = ? and v.painel_id = ? and v.titulo_id is not null limit 1');
            $st->execute([$tipo, (int) $g['painel']]);
            if ($id = $st->fetchColumn()) {
                return (int) $id;
            }
        }
        return !empty($g['nome']) ? $indice->achar($tipo, (string) $g['nome']) : null;
    }

    /**
     * Sinais (puro, testado): histórico (30 mais recentes) com "Gostei/Não gostei", avaliados sem
     * histórico, sementes do gosto e Minha lista; os 14 mais fortes.
     * @return list<array{id:int, tipo:string, nome:string, w:float, avaliado?:bool, semente?:bool, lista?:bool}>
     */
    public static function sinais(array $hist, array $notas, array $lista, array $semente, array $comecados, int $agora, array $tipos = [],
                                  array $externos = []): array
    {
        $comNota = function (int $id, float $w) use ($notas): float {
            $v = $notas[$id] ?? 0;
            return !$v ? $w : ($v > 0 ? max(abs($w), 0.8) * 1.4 : -1.2);
        };
        $sinais = [];
        $vistos = [];
        foreach (array_slice($hist, 0, 30) as $h) {
            $id = (int) $h['titulo_id'];
            $vistos[$id] = true;
            $sinais[] = ['id' => $id, 'tipo' => $h['tipo'], 'nome' => (string) $h['nome'],
                         'w' => $comNota($id, self::pesoSinal(['t' => $h['t'], 'f' => $h['f'], 'eps' => $h['eps'], 'tipo' => $h['tipo']], $agora))];
        }
        foreach ($notas as $id => $v) {
            if (!isset($vistos[$id])) {
                $sinais[] = ['id' => (int) $id, 'tipo' => $tipos[$id] ?? '?', 'nome' => '', 'w' => $v > 0 ? 1.1 : -1.0, 'avaliado' => true];
                $vistos[$id] = true;
            }
        }
        foreach ($semente as $id => $w) {
            if (!isset($vistos[$id])) {
                $sinais[] = ['id' => (int) $id, 'tipo' => $tipos[$id] ?? '?', 'nome' => '', 'w' => 0.9 * $w, 'semente' => true];
                $vistos[$id] = true;
            }
        }
        foreach ($externos as $x) {                      // Netflix: id do catálogo ou negativo (fora da lista)
            if (!isset($vistos[$x['id']])) {
                $sinais[] = $x;
                $vistos[$x['id']] = true;
            }
        }
        foreach ($lista as $id) {
            if (!isset($comecados[$id]) && !isset($vistos[$id])) {
                $sinais[] = ['id' => (int) $id, 'tipo' => $tipos[$id] ?? '?', 'nome' => '', 'w' => 0.6, 'lista' => true];
                $vistos[$id] = true;
            }
        }
        $sinais = array_values(array_filter($sinais, fn($s) => abs($s['w']) > 0.03));
        usort($sinais, fn($a, $b) => abs($b['w']) <=> abs($a['w']));
        return array_slice($sinais, 0, 14);
    }

    /**
     * Features dos títulos pelos detalhes TMDB (busca sob demanda os que ainda não têm, até `$maxBuscas`).
     * @return array<int, array{tmdb:int, tipo:string, nome:string, generos:list<int>, keywords:list<int>, pessoas:list<int>, decada:int, colecao:int, ano:int}>
     */
    private static function features(array $ids, int $maxBuscas = 14): array
    {
        if (!$ids) {
            return [];
        }
        $pdo = Db::pdo();
        $ler = function () use ($pdo, $ids): array {
            $st = $pdo->prepare("select id, tipo, nome, ano, tmdb_id, generos, colecao_id, detalhes, detalhes_em is not null as com_det
                                   from play.titulo where id = any(cast(? as bigint[]))");
            $st->execute([Banco::ints($ids)]);
            $r = [];
            foreach ($st->fetchAll() as $t) {
                $r[(int) $t['id']] = $t;
            }
            return $r;
        };
        $linhas = $ler();
        $faltam = array_keys(array_filter($linhas, fn($t) => !Banco::bool($t['com_det'])));
        if ($faltam && Tmdb::configurado()) {
            foreach (array_slice($faltam, 0, $maxBuscas) as $id) {
                try {
                    Tmdb::atualizarTitulo($id);
                } catch (\PDOException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    Log::info("recomendações: detalhes do título $id: " . $e->getMessage());
                    break;
                }
            }
            $linhas = $ler();
        }
        $saida = [];
        foreach ($linhas as $id => $t) {
            if ($t['tmdb_id'] === null) {
                continue;
            }
            $d = json_decode((string) $t['detalhes'], true) ?: [];
            if (!empty($d['sem_tmdb'])) {
                continue;
            }
            $pessoas = array_map(fn($p) => (int) $p['tmdb'], array_slice($d['elenco'] ?? [], 0, 6));
            foreach ($t['tipo'] === 's' ? ($d['criadores'] ?? []) : ($d['diretor'] ?? []) as $p) {
                $pessoas[] = (int) $p['tmdb'];
            }
            $ano = (int) ($t['ano'] ?? 0) ?: (int) substr((string) ($d['data'] ?? ''), 0, 4);
            $saida[$id] = ['tmdb' => (int) $t['tmdb_id'], 'tipo' => $t['tipo'], 'nome' => (string) $t['nome'],
                           'generos' => Banco::lerInts($t['generos']), 'keywords' => array_map('intval', $d['keywords'] ?? []),
                           'pessoas' => $pessoas, 'decada' => $ano ? intdiv($ano, 10) : 0, 'colecao' => (int) ($t['colecao_id'] ?? 0),
                           'ano' => $ano, 'nome_original' => $d['nome_original'] ?? null];
        }
        return $saida;
    }

    /**
     * Sinais da Netflix (preferência 'netflix', ver Dados\Netflix): os 60 mais fortes. Obra que está na lista IPTV
     * vira "já vista" (não é recomendada); fora da lista ganha id negativo (-(tmdb*2 + série)).
     */
    private static function sinaisNetflix(int $perfil, Indice $indice, array &$comecados, int $agora): array
    {
        $nf = Netflix::doPerfil($perfil);
        if (!$nf) {
            return [];
        }
        $saida = [];
        foreach ($nf['obras'] as $o) {
            if (empty($o['tmdb'])) {
                continue;
            }
            $tipo = ($o['t'] ?? 'm') === 's' ? 's' : 'm';
            $id = $indice->porTmdb($tipo, (int) $o['tmdb']);
            if ($id) {
                $comecados[$id] = true;
            }
            $saida[] = ['id' => $id ?: -((int) $o['tmdb'] * 2 + ($tipo === 's' ? 1 : 0)), 'tipo' => $tipo, 'tmdb' => (int) $o['tmdb'],
                        'nome' => (string) $o['n'], 'w' => Netflix::peso($o, $agora), 'netflix' => true];
        }
        usort($saida, fn($a, $b) => $b['w'] <=> $a['w']);
        return array_slice($saida, 0, 60);
    }

    /** Traços (como features()) de obras fora do catálogo, pelos detalhes do TMDB guardados 30 dias. */
    private static function featuresExternas(array $sinais): array
    {
        $saida = [];
        foreach ($sinais as $s) {
            if (empty($s['tmdb'])) {
                continue;
            }
            $tipo = ($s['tipo'] ?? 'm') === 's' ? 's' : 'm';
            try {
                $e = Tmdb::cache(Tmdb::detalhesUrl($tipo, (int) $s['tmdb']), 30 * 86400, fn($d) => !empty($d['id']) ? Tmdb::enxuto($d, $tipo) : []);
            } catch (\PDOException $x) {
                throw $x;
            } catch (\Throwable $x) {
                continue;
            }
            if (empty($e['tmdb'])) {
                continue;
            }
            $d = $e['detalhes'] ?? [];
            $pessoas = array_map(fn($p) => (int) $p['tmdb'], array_slice($d['elenco'] ?? [], 0, 6));
            foreach ($tipo === 's' ? ($d['criadores'] ?? []) : ($d['diretor'] ?? []) as $p) {
                $pessoas[] = (int) $p['tmdb'];
            }
            $ano = (int) ($e['ano'] ?? 0);
            $saida[(int) $s['id']] = ['tmdb' => (int) $e['tmdb'], 'tipo' => $tipo, 'nome' => (string) ($d['nome_tmdb'] ?? $s['nome'] ?? ''),
                'generos' => array_map('intval', $e['generos'] ?? []), 'keywords' => array_map('intval', $d['keywords'] ?? []),
                'pessoas' => $pessoas, 'decada' => $ano ? intdiv($ano, 10) : 0, 'colecao' => (int) ($e['colecao']['id'] ?? 0),
                'ano' => $ano, 'nome_original' => $d['nome_original'] ?? null];
        }
        return $saida;
    }

    /** Perfil de gosto (puro, testado): somas ponderadas normalizadas + semente de gêneros/pessoas + época. */
    public static function montarPerfil(array $sinais, array $gosto, ?array $epoca): array
    {
        $p = ['generos' => [], 'keywords' => [], 'pessoas' => [], 'decadas' => []];
        $somar = function (array &$mapa, array $chaves, float $w): void {
            foreach ($chaves as $k) {
                $mapa[$k] = ($mapa[$k] ?? 0) + $w;
            }
        };
        foreach ($sinais as $s) {
            $somar($p['generos'], $s['f']['generos'], $s['w']);
            $somar($p['keywords'], $s['f']['keywords'], $s['w']);
            $somar($p['pessoas'], $s['f']['pessoas'], $s['w']);
            if ($s['f']['decada']) {
                $somar($p['decadas'], [$s['f']['decada']], $s['w']);
            }
        }
        $pico = $p['generos'] ? max(1e-9, max($p['generos'])) : 1.0;
        $pico = $pico > 0 ? $pico : 1.0;
        foreach ($gosto['generos'] ?? [] as $g => $w) {
            $p['generos'][(int) $g] = ($p['generos'][(int) $g] ?? 0) + $pico * (float) $w;
        }
        foreach ($gosto['pessoas'] ?? [] as $x) {
            $id = (int) ($x['tmdb'] ?? 0);
            if ($id) {
                $p['pessoas'][$id] = ($p['pessoas'][$id] ?? 0) + 3 * (float) ($x['w'] ?? 1);
            }
        }
        if ($epoca && !empty($epoca['keyword'])) {
            $p['keywords'][(int) $epoca['keyword']] = ($p['keywords'][(int) $epoca['keyword']] ?? 0) + 2;
        }
        foreach ($p as $k => $mapa) {
            $max = 0.0;
            foreach ($mapa as $v) {
                $max = max($max, abs($v));
            }
            if ($max > 0) {
                foreach ($mapa as $i => $v) {
                    $p[$k][$i] = $v / $max;
                }
            }
        }
        $top = array_filter($p['generos'], fn($v) => $v > 0);
        arsort($top);
        $p['top_generos'] = array_map('intval', array_keys($top));
        return $p;
    }

    /** Fontes de candidatos (TMDB e JustWatch, com cache). */
    private static function fontes(array $sinais, array $perfil, array $gosto, ?array $epoca, int $agora): array
    {
        $sets = [];
        $ler = function (string $caminho) {
            try {
                return Tmdb::cache($caminho, 7 * 86400, [Tmdb::class, 'enxugarLista']);
            } catch (\PDOException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::info('recomendações: TMDB ' . strtok($caminho, '?') . ': ' . $e->getMessage());
                return [];
            }
        };
        foreach ($sinais as $s) {
            $base = ($s['tipo'] === 's' ? 'tv/' : 'movie/') . $s['tmdb'];
            $sets[] = ['sinal' => $s['id'], 'w' => $s['w'], 'tipo' => $s['tipo'], 'lista' => $ler("$base/recommendations?language=pt-BR")['results'] ?? []];
            if ($s['w'] > 0) {
                $sets[] = ['sinal' => $s['id'], 'w' => $s['w'] * 0.55, 'tipo' => $s['tipo'], 'lista' => $ler("$base/similar?language=pt-BR")['results'] ?? []];
            }
        }
        $positivos = array_filter($sinais, fn($s) => $s['w'] > 0);
        $series = count(array_filter($positivos, fn($s) => $s['tipo'] === 's'));
        $principal = $series * 2 >= count($sinais) ? 'tv' : 'movie';
        foreach (array_slice($perfil['top_generos'], 0, 6) as $i => $g) {
            foreach (['movie', 'tv'] as $midia) {
                $w = ($i < 2 ? 0.35 - $i * 0.1 : 0.12) * ($midia === $principal ? 1 : 0.7);
                $sets[] = ['sinal' => null, 'w' => $w, 'tipo' => null, 'lista' => self::comMidia(
                    $ler("discover/$midia?language=pt-BR&watch_region=BR&with_genres=$g&sort_by=popularity.desc&vote_count.gte=150")['results'] ?? [], $midia)];
            }
        }
        foreach ($gosto['pessoas'] ?? [] as $x) {
            if (!empty($x['tmdb'])) {
                $sets[] = ['sinal' => null, 'w' => 0.6 * (float) ($x['w'] ?? 1), 'tipo' => 'm',
                           'lista' => self::comMidia($ler('person/' . (int) $x['tmdb'] . '/movie_credits?language=pt-BR')['cast'] ?? [], 'movie')];
            }
        }
        $epocaListas = [];
        if ($epoca && !empty($epoca['keyword'])) {
            $kw = (int) $epoca['keyword'];
            $l = self::comMidia($ler("discover/movie?language=pt-BR&watch_region=BR&with_keywords=$kw&sort_by=popularity.desc&vote_count.gte=50")['results'] ?? [], 'movie');
            $sets[] = ['sinal' => null, 'w' => 0.3, 'tipo' => 'm', 'lista' => $l];
            $epocaListas = $l;
            // os populares são quase todos antigos: os mais novos também (lançados até hoje)
            $hoje = date('Y-m-d', $agora);
            foreach ([1, 2] as $pag) {
                $l = self::comMidia($ler("discover/movie?language=pt-BR&watch_region=BR&with_keywords=$kw&sort_by=primary_release_date.desc&vote_count.gte=10&primary_release_date.lte=$hoje&page=$pag")['results'] ?? [], 'movie');
                $sets[] = ['sinal' => null, 'w' => 0.15, 'tipo' => 'm', 'lista' => $l];
                $epocaListas = array_merge($epocaListas, $l);
            }
        }
        $nomes = [];
        foreach (['movie', 'tv'] as $midia) {
            foreach ($ler("genre/$midia/list?language=pt-BR")['genres'] ?? [] as $g) {
                $nomes[(int) $g['id']] = (string) $g['name'];
            }
        }
        $tendencia = array_values(array_filter($ler('trending/all/week?language=pt-BR')['results'] ?? [], fn($r) => in_array($r['media_type'] ?? '', ['movie', 'tv'], true)));
        if ($tendencia) {
            $sets[] = ['sinal' => null, 'w' => 0.2, 'tipo' => null, 'lista' => $tendencia];
        }
        // JustWatch: parecidos de cada like e "em alta no Brasil"
        $jw = JustWatch::notasDosTitulos(array_column($positivos, 'id'), true);
        foreach ($positivos as $s) {
            $par = $jw[$s['id']]['parecidos'] ?? [];
            if ($par) {
                $sets[] = ['sinal' => $s['id'], 'w' => $s['w'] * 0.5, 'tipo' => null, 'lista' => array_values(array_filter(array_map([self::class, 'jwComoTmdb'], $par)))];
            }
        }
        $brPos = [];
        try {
            $br = JustWatch::emAltaBrasil();
            $lista = array_values(array_filter(array_map([self::class, 'jwComoTmdb'], $br['all'] ?? [])));
            foreach ($lista as $i => $r) {
                $brPos[$r['media_type'] . $r['id']] = $i;
            }
            if ($lista) {
                $sets[] = ['sinal' => null, 'w' => 0.2, 'tipo' => null, 'lista' => $lista];
            }
        } catch (\Throwable $e) {
            Log::info('recomendações: JustWatch em alta: ' . $e->getMessage());
        }
        return ['sets' => $sets, 'nomes' => $nomes, 'brPos' => $brPos, 'epoca' => $epocaListas];
    }

    private static function comMidia(array $lista, string $midia): array
    {
        return array_map(fn($r) => $r + ['media_type' => $midia], $lista);
    }

    /** Entrada do JustWatch no formato TMDB (jwAsTmdb da v10). */
    public static function jwComoTmdb(?array $x): ?array
    {
        if (!$x || empty($x['tmdb'])) {
            return null;
        }
        $tv = $x['tipo'] === 's';
        $data = $x['ano'] ? $x['ano'] . '-01-01' : '';
        $r = ['id' => (int) $x['tmdb'], 'media_type' => $tv ? 'tv' : 'movie', 'genre_ids' => $x['generos'] ?? [],
              'vote_average' => (float) ($x['notas']['imdb'] ?? 0), 'vote_count' => (int) ($x['notas']['imdb_votos'] ?? 0), '_jw' => 1];
        if ($tv) {
            $r += ['name' => $x['nome'], 'original_name' => $x['nome_original'], 'first_air_date' => $data];
        } else {
            $r += ['title' => $x['nome'], 'original_title' => $x['nome_original'], 'release_date' => $data];
        }
        return $r;
    }

    /**
     * Pontuação dos candidatos (puro, testado). sets = [{sinal, w, tipo, lista:[r TMDB]}].
     * @return list<array{tipo:string, r:array, apoio:float, fontes:int, porque:array<int,float>, qualidade:float, nota:float}>
     */
    public static function pontuar(array $sets, array $perfil, bool $blockbuster, array $brPos, int $anoAtual): array
    {
        $cands = [];
        foreach ($sets as $set) {
            foreach (array_values($set['lista']) as $rank => $r) {
                if (empty($r['id'])) {
                    continue;
                }
                $midia = $set['sinal'] !== null && $set['tipo'] ? ($set['tipo'] === 's' ? 'tv' : 'movie')
                    : ($r['media_type'] ?? (isset($r['title']) ? 'movie' : 'tv'));
                if (!in_array($midia, ['movie', 'tv'], true)) {
                    continue;
                }
                $k = $midia . $r['id'];
                $c = &$cands[$k];
                $c ??= ['tipo' => $midia === 'tv' ? 's' : 'm', 'r' => $r, 'apoio' => 0.0, 'fontes' => 0, 'porque' => []];
                $contrib = $set['w'] / (1 + $rank * 0.09);
                $c['apoio'] += $contrib;
                $c['fontes'] += $set['w'] > 0 ? 1 : 0;
                if ($set['sinal'] !== null && $contrib > 0) {
                    $c['porque'][$set['sinal']] = ($c['porque'][$set['sinal']] ?? 0) + $contrib;
                }
                unset($c);
            }
        }
        $saida = [];
        foreach ($cands as $k => $c) {
            if ($c['apoio'] <= 0) {
                continue;
            }
            $r = $c['r'];
            $generos = $r['genre_ids'] ?? [];
            $match = 0.0;
            foreach ($generos as $g) {
                $match += $perfil['generos'][$g] ?? 0;
            }
            $match = $generos ? $match / sqrt(count($generos)) : 0;
            $ano = (int) substr((string) ($r['release_date'] ?? $r['first_air_date'] ?? ''), 0, 4);
            $decada = $ano ? ($perfil['decadas'][intdiv($ano, 10)] ?? 0) : 0;
            $votos = (int) ($r['vote_count'] ?? 0);
            $media = (float) ($r['vote_average'] ?? 0) ?: 5.0;
            $q = 0.75 + 0.5 * ($media / 10) * min(1, log($votos + 1) / log(2000));
            if ($blockbuster) {
                $q *= 1 + 0.3 * min(1, log($votos + 1) / log(20000));
            }
            $corrob = 1 + 0.15 * min(4, max(0, $c['fontes'] - 1));
            $br = isset($brPos[$k]) ? 1 + 0.12 * (1 - $brPos[$k] / 40) : 1.0;
            $c['qualidade'] = $q;
            $c['nota'] = $c['apoio'] * $corrob * (1 + 0.45 * max(-0.5, $match) + 0.1 * $decada) * $q * self::recencia($ano, $anoAtual) * $br;
            if ($c['nota'] > 0) {
                $saida[] = $c;
            }
        }
        usort($saida, fn($a, $b) => $b['nota'] <=> $a['nota']);
        return $saida;
    }

    /**
     * Variedade e fileiras (finishRecs da v10, puro, testado). escolhas ordenadas por nota, cada uma com
     * id, tipo, r, nota, porque, genero, colecao.
     * @return array{recomendados:list<int>, porque:list<array{titulo_id:int, ids:list<int>}>, generos:list<array{tmdb:int, nome:string, ids:list<int>}>}
     */
    public static function finalizar(array $escolhas, array $perfil, array $sinais, array $nomesGenero): array
    {
        $top3 = array_slice($perfil['top_generos'], 0, 3);
        $explorar = array_values(array_filter($escolhas, fn($c) => !in_array((int) ($c['genero'] ?: ($c['r']['genre_ids'][0] ?? 0)), $top3, true)
            && (float) ($c['r']['vote_average'] ?? 0) >= 7));
        $saida = [];
        $porGenero = [];
        $colecoes = [];
        $usados = [];
        while (count($saida) < 40 && $escolhas) {
            $usarExplorar = count($saida) % 7 === 6 && $explorar;
            $pool = $usarExplorar ? $explorar : $escolhas;
            $melhor = null;
            $melhorNota = -INF;
            foreach (array_slice($pool, 0, 60) as $c) {
                if (isset($usados[$c['id']])) {
                    continue;
                }
                $pena = 0.8 ** ($porGenero[$c['genero']] ?? 0) * ($c['colecao'] && isset($colecoes[$c['colecao']]) ? 0.55 : 1);
                if ($c['nota'] * $pena > $melhorNota) {
                    $melhorNota = $c['nota'] * $pena;
                    $melhor = $c;
                }
            }
            if (!$melhor) {
                if ($usarExplorar) {
                    $explorar = [];
                    continue;
                }
                break;
            }
            $usados[$melhor['id']] = true;
            $porGenero[$melhor['genero']] = ($porGenero[$melhor['genero']] ?? 0) + 1;
            if ($melhor['colecao']) {
                $colecoes[$melhor['colecao']] = true;
            }
            $saida[] = $melhor['id'];
            if (count($usados) >= count($escolhas)) {
                break;
            }
        }
        // "Porque você assistiu X": os dois likes mais fortes vistos aqui e o que eles puxam.
        // "Gostei" sem histórico também vale (pedido 09/11/2026 noite: gostei = já vi).
        $porque = [];
        foreach ($sinais as $s) {
            if ($s['w'] <= 0 || !empty($s['lista']) || count($porque) >= 2) {
                continue;
            }
            $ligados = array_values(array_filter($escolhas, fn($c) => isset($c['porque'][$s['id']])));
            usort($ligados, fn($a, $b) => $b['porque'][$s['id']] <=> $a['porque'][$s['id']]);
            $ids = array_column(array_slice($ligados, 0, 20), 'id');
            if (count($ids) >= 4) {
                $porque[] = ['titulo_id' => $s['id'], 'nome' => (string) ($s['nome'] ?? ''), 'ids' => $ids];
            }
        }
        // "<gênero> para você": os gêneros mais fortes do perfil, com os melhores de cada um
        $generos = [];
        foreach ($perfil['top_generos'] as $g) {
            if (count($generos) >= 6) {
                break;
            }
            if (!isset($nomesGenero[$g])) {
                continue;
            }
            $ids = array_column(array_slice(array_values(array_filter($escolhas, fn($c) => in_array($g, $c['r']['genre_ids'] ?? [], true))), 0, 25), 'id');
            if (count($ids) >= 5) {
                $generos[] = ['tmdb' => $g, 'nome' => $nomesGenero[$g], 'ids' => $ids];
            }
        }
        return ['recomendados' => $saida, 'porque' => $porque, 'generos' => $generos];
    }

    /**
     * "<gênero> para você" pelos gêneros do JustWatch (decisão 08/11/2026). Gêneros = os mais fortes do perfil
     * traduzidos para o JustWatch; em cada um, o catálogo inteiro do gênero (400 mais votados), nota = gosto
     * (gêneros, palavras-chave, elenco/direção, década) × qualidade JustWatch (IMDb/Rotten/curtidas; sem ela, TMDB)
     * × recência, com bônus para o que o recomendador principal também escolheu. Sem repetir título entre as
     * fileiras nem os 10 primeiros recomendados; uma obra por coleção.
     * @return list<array{jw:string, nome:string, ids:list<int>}>
     */
    private static function fileirasGenero(array $perfil, callable $elegivel, bool $blockbuster, array $escolhas, array $recomendados, int $agora,
                                           ?string $tipo = null, int $max = 4): array
    {
        $pdo = Db::pdo();
        $notaRec = [];
        foreach ($escolhas as $c) {
            $notaRec[(int) $c['id']] = (float) $c['nota'];
        }
        $maxRec = $notaRec ? max(1e-9, max($notaRec)) : 1.0;
        $usados = array_flip(array_slice($recomendados, 0, 10));
        $anoAtual = (int) date('Y', $agora);
        $saida = [];
        foreach (array_keys(Generos::pesosDoPerfil($perfil['generos'])) as $cod) {
            if (count($saida) >= $max) {
                break;
            }
            [$filtro, $par] = Generos::filtro($cod);
            $st = $pdo->prepare("select t.id, t.jw, cast(t.detalhes->>'nota_tmdb' as numeric) as nota_tmdb,
                                        cast(t.detalhes->>'votos_tmdb' as numeric) as votos_tmdb
                                   from play.titulo t
                                  where t.ativo and t.link_ok is not false and t.detalhes_em is not null and $filtro
                                    and (cast(? as text) is null or t.tipo = cast(? as text))
                                  order by coalesce(cast(t.jw->>'imdb_votos' as numeric), cast(t.detalhes->>'votos_tmdb' as numeric) * 20, 0) desc
                                  limit 400");
            $st->execute([...$par, $tipo, $tipo]);
            $linhas = [];
            foreach ($st->fetchAll() as $l) {
                $id = (int) $l['id'];
                if ($elegivel($id) && !isset($usados[$id])) {
                    $linhas[$id] = $l;
                }
            }
            $feat = self::features(array_keys($linhas), 0);
            $cands = [];
            foreach ($linhas as $id => $l) {
                $fx = $feat[$id] ?? null;
                if (!$fx) {
                    continue;
                }
                $match = 0.0;
                foreach ($fx['generos'] as $g) {
                    $match += $perfil['generos'][$g] ?? 0;
                }
                $match = $fx['generos'] ? $match / sqrt(count($fx['generos'])) : 0.0;
                $kw = 0.0;
                foreach ($fx['keywords'] as $x) {
                    $kw += max(0, $perfil['keywords'][$x] ?? 0);
                }
                $ppl = 0.0;
                foreach ($fx['pessoas'] as $x) {
                    $ppl += max(0, $perfil['pessoas'][$x] ?? 0);
                }
                $dec = $fx['decada'] ? ($perfil['decadas'][$fx['decada']] ?? 0) : 0;
                $q = JustWatch::qualidade(json_decode((string) $l['jw'], true) ?: null, $blockbuster);
                if ($q <= 0) {
                    $votos = (int) ($l['votos_tmdb'] ?? 0);
                    $q = 0.75 + 0.5 * ((float) ($l['nota_tmdb'] ?? 5) / 10) * min(1, log($votos + 1) / log(2000));
                }
                $bonus = isset($notaRec[$id]) ? 1 + 0.5 * ($notaRec[$id] / $maxRec) : 1.0;
                // gênero da fileira como principal (1º do JustWatch, senão do TMDB) pesa mais: "Drama" não vira ficção com drama
                $gs = Generos::doTitulo((json_decode((string) $l['jw'], true) ?: [])['g'] ?? null, $fx['generos']);
                $pureza = ($gs[0] ?? null) === $cod ? 1.0 : (count($gs) <= 2 ? 0.8 : 0.6);
                $nota = (1 + 0.45 * max(-0.5, $match) + 0.1 * $dec) * (1 + 0.12 * min(5, $kw) + 0.18 * min(3, $ppl))
                    * $q * self::recencia((int) $fx['ano'], $anoAtual) * $bonus * $pureza;
                $cands[] = ['id' => $id, 'nota' => $nota, 'colecao' => (int) $fx['colecao']];
            }
            usort($cands, fn($a, $b) => $b['nota'] <=> $a['nota']);
            $ids = [];
            $colecoes = [];
            foreach ($cands as $c) {
                if (count($ids) >= 25) {
                    break;
                }
                if ($c['colecao'] && isset($colecoes[$c['colecao']])) {
                    continue;
                }
                $ids[] = $c['id'];
                if ($c['colecao']) {
                    $colecoes[$c['colecao']] = true;
                }
            }
            if (count($ids) >= 6) {
                foreach ($ids as $id) {
                    $usados[$id] = true;
                }
                $saida[] = ['jw' => $cod, 'nome' => Generos::NOMES[$cod], 'ids' => $ids];
            }
        }
        return $saida;
    }

    /**
     * Completa uma lista de recomendados até `$n` intercalando os melhores de cada fileira de gênero (puro):
     * quando o perfil quase não tem sinal de um tipo, "Para você" ainda mostra 40 títulos pelo gosto.
     */
    public static function completar(array $lista, array $fileiras, int $n): array
    {
        $saida = array_values(array_unique(array_map('intval', $lista)));
        $usados = array_flip($saida);
        for ($i = 0; count($saida) < $n; $i++) {
            $achou = false;
            foreach ($fileiras as $f) {
                $id = isset($f['ids'][$i]) ? (int) $f['ids'][$i] : null;
                if ($id === null) {
                    continue;
                }
                $achou = true;
                if (!isset($usados[$id]) && count($saida) < $n) {
                    $usados[$id] = true;
                    $saida[] = $id;
                }
            }
            if (!$achou) {
                break;
            }
        }
        return $saida;
    }

    /** Destaque: os 5 primeiros recomendados que têm imagem de fundo (senão os 5 primeiros). */
    /** Destaque do Início: 10 títulos (09/11, eram 5), primeiro os que têm fundo E trailer, depois os com fundo. */
    private static function destaque(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $janela = array_slice($ids, 0, 40);
        $st = Db::pdo()->prepare("select id, (coalesce(detalhes->>'trailer', '') <> '') as trailer from play.titulo
                                   where id = any(cast(? as bigint[])) and fundo is not null");
        $st->execute([Banco::ints($janela)]);
        $fundo = [];
        $trailer = [];
        foreach ($st->fetchAll() as $l) {
            $fundo[(int) $l['id']] = true;
            if (Banco::bool($l['trailer'])) {
                $trailer[(int) $l['id']] = true;
            }
        }
        $d = array_values(array_filter($janela, fn($id) => isset($trailer[$id])));
        foreach ([array_filter($janela, fn($id) => isset($fundo[$id])), $ids] as $resto) {
            foreach ($resto as $id) {
                if (count($d) >= 10) {
                    break 2;
                }
                if (!in_array($id, $d, true)) {
                    $d[] = $id;
                }
            }
        }
        return array_slice($d, 0, 10);
    }

    /** Fileira da época: filmes da palavra-chave (sem terror) + categoria do painel, mais novos primeiro. */
    private static function fileiraEpoca(Indice $indice, array $listaTmdb, array $epoca, callable $elegivel): array
    {
        $achados = [];
        $vistos = [];
        foreach ($listaTmdb as $r) {
            if (in_array(27, $r['genre_ids'] ?? [], true)) {
                continue;
            }
            $id = $indice->casarTmdb($r, 'm');
            if ($id && !isset($vistos[$id]) && $elegivel($id)) {
                $vistos[$id] = true;
                $achados[] = ['id' => $id, 'ano' => (int) substr((string) ($r['release_date'] ?? ''), 0, 4) ?: $indice->ano($id), 'ordem' => count($achados)];
            }
        }
        if (!empty($epoca['categoria_painel'])) {
            $st = Db::pdo()->prepare("
                select t.id, coalesce(t.ano, 0) as ano from play.titulo t
                 where t.ativo and t.tipo = 'm' and t.categorias && (select coalesce(array_agg(c.painel_id), '{}') from play.categoria c
                                                                        where c.tipo = 'm' and play.norm(c.nome) like '%' || play.norm(?) || '%')
                 order by t.adicionado_em desc nulls last limit 80");
            $st->execute([(string) $epoca['categoria_painel']]);
            foreach ($st->fetchAll() as $t) {
                $id = (int) $t['id'];
                if (!isset($vistos[$id]) && $elegivel($id) && Texto::latino($indice->nome($id))) {
                    $vistos[$id] = true;
                    $achados[] = ['id' => $id, 'ano' => (int) $t['ano'], 'ordem' => count($achados)];
                }
            }
        }
        usort($achados, fn($a, $b) => [$b['ano'], $a['ordem']] <=> [$a['ano'], $b['ordem']]);
        return array_column($achados, 'id');
    }

    /** Resumo do gosto para o painel/TV: gêneros e pessoas mais fortes. */
    private static function resumoGosto(array $p, array $nomesGenero): array
    {
        $generos = [];
        foreach (array_slice($p['top_generos'], 0, 6) as $g) {
            $generos[] = ['tmdb' => $g, 'nome' => $nomesGenero[$g] ?? null, 'peso' => round($p['generos'][$g], 3)];
        }
        $pessoas = array_filter($p['pessoas'], fn($v) => $v > 0);
        arsort($pessoas);
        $pessoas = array_slice($pessoas, 0, 6, true);
        $nomes = [];
        if ($pessoas) {
            $st = Db::pdo()->prepare('select tmdb_id, nome from play.pessoa where tmdb_id = any(cast(? as int[]))');
            $st->execute([Banco::ints(array_keys($pessoas))]);
            $nomes = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
        $lista = [];
        foreach ($pessoas as $id => $v) {
            $lista[] = ['tmdb' => (int) $id, 'nome' => $nomes[$id] ?? null, 'peso' => round($v, 3)];
        }
        return ['generos' => $generos, 'pessoas' => $lista];
    }

    /**
     * Perfil sem nenhum like ainda: em alta no Brasil e Top 10 que estão na lista, pela qualidade.
     */
    private static function partidaFria(Indice $indice, callable $elegivel, ?array $epoca, int $agora): array
    {
        $ids = [];
        try {
            foreach (JustWatch::emAltaBrasil()['all'] ?? [] as $x) {
                $r = self::jwComoTmdb($x);
                $id = $r ? $indice->casarTmdb($r, $x['tipo']) : null;
                if ($id && $elegivel($id)) {
                    $ids[$id] = JustWatch::qualidade($x['notas'] ?? null, false) ?: 0.8;
                }
            }
        } catch (\Throwable $e) {
            Log::info('recomendações (partida fria): ' . $e->getMessage());
        }
        foreach (Db::pdo()->query("select distinct on (titulo_id) titulo_id from play.top10
                                     where titulo_id is not null and coletado_em > now() - interval '2 days'")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $id = (int) $id;
            if ($elegivel($id) && !isset($ids[$id])) {
                $ids[$id] = 0.7;
            }
        }
        arsort($ids);
        $lista = array_slice(array_map('intval', array_keys($ids)), 0, 40);
        $epocaIds = [];
        if ($epoca) {
            $f = self::fontes([], ['top_generos' => []], [], $epoca, $agora);
            $epocaIds = self::fileiraEpoca($indice, $f['epoca'], $epoca, $elegivel);
        }
        return [
            'destaque'     => self::destaque($lista),
            'recomendados' => $lista,
            'porque'       => [],
            'generos'      => [],
            'epoca'        => count($epocaIds) >= 4 ? ['titulo' => $epoca['titulo'] ?? self::EPOCA_PADRAO['titulo'], 'subtitulo' => 'Os mais novos primeiro', 'ids' => array_slice($epocaIds, 0, 40)] : null,
            'gosto'        => ['generos' => [], 'pessoas' => []],
        ];
    }
}
