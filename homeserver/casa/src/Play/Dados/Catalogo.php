<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;
use PDO;
use RuntimeException;

/**
 * Tarefa "catalogo" (6 h e sob demanda): categorias, filmes, séries e canais do painel, UMA LISTA DE
 * CADA VEZ (o painel recusa as listas grandes em paralelo).
 *
 * Cada entrada do painel vira uma linha em play.versao; as versões da mesma obra (dublada, "Nome [L]"
 * legendada, cópias "4K [DV][HDR]", o mesmo filme listado duas vezes) apontam para uma linha de
 * play.titulo. A junção segue a v10 (pairVersions/preferredCopy): mesmo id TMDB do painel quando há;
 * senão o mesmo nome normalizado com ano ±1. O id de título já existente é preservado (histórico,
 * listas e notas apontam para ele).
 */
final class Catalogo
{
    private const LOTE = 2000;

    public static function tarefa(): array
    {
        $conta = Xtream::contaAtiva();
        if (!$conta) {
            return ['msg' => 'sem conta ativa'];
        }
        $partes = [];
        $novos = 0;
        foreach (['m' => 'get_vod_categories', 's' => 'get_series_categories', 'c' => 'get_live_categories'] as $tipo => $acao) {
            $partes[] = self::categorias($conta, $tipo, (array) Xtream::api($conta, $acao, [], 60)) . " cat. $tipo";
        }
        Tarefas::ceder();
        foreach (['m' => 'get_vod_streams', 's' => 'get_series'] as $tipo => $acao) {
            $ini = microtime(true);
            $lista = Xtream::api($conta, $acao, [], 240);
            if (!is_array($lista) || !array_is_list($lista)) {
                throw new RuntimeException("$acao: resposta inesperada do painel");
            }
            $versoes = self::lerVersoes($tipo, $lista);
            unset($lista);
            $r = self::sincronizarVersoes($conta['id'], $tipo, $versoes);
            unset($versoes);
            $novos += $r['novos'];
            $partes[] = sprintf('%s: %d versões, %d títulos (%d novos, %d saíram) em %.0f s',
                $tipo === 'm' ? 'filmes' : 'séries', $r['versoes'], $r['titulos'], $r['novos'], $r['sairam'], microtime(true) - $ini);
            gc_collect_cycles();
            if (Tarefas::ceder()) {
                return ['msg' => 'interrompido: ' . implode('; ', $partes), 'proxima' => time() + 60];
            }
        }
        $canais = Xtream::api($conta, 'get_live_streams', [], 120);
        $partes[] = self::sincronizarCanais($conta['id'], is_array($canais) ? $canais : []) . ' canais';
        unset($canais);

        Indice::invalidar();
        $chegaram = self::avisosChegar();
        if ($chegaram) {
            $partes[] = "$chegaram aviso(s) de chegada";
        }
        Sinais::atualizado('catalogo');
        Tarefas::forcar('seguidas');
        Tarefas::forcar('top10');
        if ($novos) {
            Tarefas::forcar('tmdb');
        }
        return ['msg' => implode('; ', $partes)];
    }

    // ------------------------------------------------------------ categorias e canais

    private static function categorias(array $conta, string $tipo, array $lista): int
    {
        $linhas = [];
        foreach ($lista as $c) {
            if (!isset($c['category_id'], $c['category_name'])) {
                continue;
            }
            $nome = (string) $c['category_name'];
            $linhas[(int) $c['category_id']] = ['p' => (int) $c['category_id'], 'n' => $nome, 'e' => Texto::acentuar($nome),
                         'g' => $tipo !== 'c' && Texto::ehGenero($nome)];
        }
        if (!$linhas) {
            return 0;          // painel falhou: não apaga as que existem
        }
        $linhas = array_values($linhas);
        $pdo = Db::pdo();
        $pdo->prepare("
            insert into play.categoria (conta_id, tipo, painel_id, nome, nome_exib, genero)
            select ?, ?, x.p, x.n, x.e, x.g from jsonb_to_recordset(cast(? as jsonb)) as x(p int, n text, e text, g boolean)
            on conflict (conta_id, tipo, painel_id) do update set nome = excluded.nome, nome_exib = excluded.nome_exib, genero = excluded.genero")
            ->execute([$conta['id'], $tipo, Banco::json($linhas)]);
        $pdo->prepare('delete from play.categoria where conta_id = ? and tipo = ? and not (painel_id = any(cast(? as int[])))')
            ->execute([$conta['id'], $tipo, Banco::ints(array_column($linhas, 'p'))]);
        return count($linhas);
    }

    private static function sincronizarCanais(int $contaId, array $lista): int
    {
        $linhas = [];
        foreach ($lista as $l) {
            if (!isset($l['stream_id'])) {
                continue;
            }
            // mesmo canal em duas categorias: fica a primeira (upsert em lote não aceita chave repetida)
            $linhas[(int) $l['stream_id']] ??= [
                'p' => (int) $l['stream_id'], 'n' => (string) ($l['name'] ?? ''), 'k' => Texto::norm((string) ($l['name'] ?? '')),
                'num' => isset($l['num']) ? (int) $l['num'] : null, 'logo' => self::url($l['stream_icon'] ?? null),
                'cat' => isset($l['category_id']) ? (int) $l['category_id'] : null,
                'epg' => ($l['epg_channel_id'] ?? '') !== '' ? (string) $l['epg_channel_id'] : null,
                'arq' => (int) ($l['tv_archive'] ?? 0) === 1,
            ];
        }
        if (!$linhas) {
            return 0;
        }
        $linhas = array_values($linhas);
        self::upsertCanais($contaId, $linhas);
        $pdo = Db::pdo();
        $ativos = (int) $pdo->query("select count(*) from play.canal where conta_id = $contaId and ativo")->fetchColumn();
        if (count($linhas) >= $ativos * 0.5) {
            $pdo->prepare('update play.canal set ativo = false where conta_id = ? and ativo and not (painel_id = any(cast(? as int[])))')
                ->execute([$contaId, Banco::ints(array_column($linhas, 'p'))]);
        }
        return count($linhas);
    }

    /** Também usado por Jogos (canais "Jogos do Dia" são renomeados todo dia). */
    public static function upsertCanais(int $contaId, array $linhas): void
    {
        $unicas = [];
        foreach ($linhas as $l) {
            $unicas[$l['p']] ??= $l;
        }
        $linhas = array_values($unicas);
        $st = Db::pdo()->prepare("
            insert into play.canal (conta_id, painel_id, nome, nome_norm, numero, logo, categoria_id, epg_id, arquivo, ativo)
            select ?, x.p, x.n, x.k, x.num, x.logo, x.cat, x.epg, coalesce(x.arq, false), true
              from jsonb_to_recordset(cast(? as jsonb)) as x(p int, n text, k text, num int, logo text, cat int, epg text, arq boolean)
            on conflict (conta_id, painel_id) do update set
                nome = excluded.nome, nome_norm = excluded.nome_norm, numero = coalesce(excluded.numero, play.canal.numero),
                logo = excluded.logo, categoria_id = coalesce(excluded.categoria_id, play.canal.categoria_id),
                epg_id = coalesce(excluded.epg_id, play.canal.epg_id), arquivo = excluded.arquivo, ativo = true");
        foreach (array_chunk($linhas, self::LOTE) as $lote) {
            $st->execute([$contaId, Banco::json($lote)]);
        }
    }

    // ------------------------------------------------------------ filmes e séries

    /**
     * Lista do painel → versões compactas (função pura).
     * @return list<array{p:int,n:string,k:string,y:int,t:?int,leg:bool,uhd:bool,e:?string,cat:?int,cats:list<int>,c:?string,r:?float,a:?int}>
     */
    public static function lerVersoes(string $tipo, array $lista): array
    {
        $saida = [];
        $pos = [];             // painel_id => índice em $saida (o painel repete o id quando está em várias categorias)
        foreach ($lista as $x) {
            $id = (int) ($tipo === 'm' ? ($x['stream_id'] ?? 0) : ($x['series_id'] ?? 0));
            if ($id <= 0) {
                continue;
            }
            $nome = (string) ($x['name'] ?? '');
            $cats = isset($x['category_ids']) && is_array($x['category_ids']) && $x['category_ids']
                ? array_values(array_unique(array_map('intval', $x['category_ids'])))
                : (isset($x['category_id']) && $x['category_id'] !== null ? [(int) $x['category_id']] : []);
            if (isset($pos[$id])) {
                $i = $pos[$id];
                $saida[$i]['cats'] = array_values(array_unique(array_merge($saida[$i]['cats'], $cats)));
                $saida[$i]['cat'] ??= $saida[$i]['cats'][0] ?? null;
                continue;
            }
            $pos[$id] = count($saida);
            $tmdb = (int) ($x['tmdb'] ?? $x['tmdb_id'] ?? 0);
            $nota = is_numeric($x['rating'] ?? null) ? round((float) $x['rating'], 2) : null;
            $add = (int) ($tipo === 'm' ? ($x['added'] ?? 0) : ($x['last_modified'] ?? 0));
            $saida[] = [
                'p'    => $id,
                'n'    => $nome,
                'k'    => Texto::norm($nome),
                'y'    => Texto::ano($nome, $tipo === 'm' ? ($x['year'] ?? null) : ($x['year'] ?? $x['releaseDate'] ?? $x['release_date'] ?? null)),
                't'    => $tmdb > 0 ? $tmdb : null,
                'leg'  => Texto::ehLegendado($nome),
                'uhd'  => Texto::ehUhd($nome),
                'e'    => $tipo === 'm' && isset($x['container_extension']) ? (string) $x['container_extension'] : null,
                'cat'  => $cats[0] ?? null,
                'cats' => $cats,
                'c'    => self::url($tipo === 'm' ? ($x['stream_icon'] ?? null) : ($x['cover'] ?? null)),
                'r'    => $nota !== null && $nota > 0 && $nota < 100 ? $nota : null,
                'a'    => $add > 0 ? $add : null,
            ];
        }
        return $saida;
    }

    /**
     * Junta as versões em obras (função pura, testada). Devolve grupos com os índices das versões
     * em `$v`, a versão principal (dublada e sem 4K, a primeira do painel), nome, ano e tmdb.
     * @return list<array{membros:list<int>, principal:int, k:string, ano:int, tmdb:?int}>
     */
    public static function agrupar(array $v): array
    {
        $n = count($v);
        $pai = range(0, max(0, $n - 1));
        $tm = [];                                          // raiz => [tmdb => true]
        $achar = function (int $i) use (&$pai): int {
            while ($pai[$i] !== $i) {
                $pai[$i] = $pai[$pai[$i]];
                $i = $pai[$i];
            }
            return $i;
        };
        $unir = function (int $a, int $b, bool $mesmoTmdb = false) use (&$pai, &$tm, $achar): bool {
            $ra = $achar($a);
            $rb = $achar($b);
            if ($ra === $rb) {
                return true;
            }
            $ta = $tm[$ra] ?? [];
            $tb = $tm[$rb] ?? [];
            if (!$mesmoTmdb && $ta && $tb && !array_intersect_key($ta, $tb)) {
                return false;                              // ids TMDB diferentes: obras diferentes
            }
            $pai[$rb] = $ra;
            $tm[$ra] = $ta + $tb;
            unset($tm[$rb]);
            return true;
        };
        $porTmdb = [];
        $porNome = [];
        foreach ($v as $i => $x) {
            if ($x['t']) {
                $tm[$i] = [$x['t'] => true];
            }
            if ($x['k'] !== '') {
                $porNome[$x['k']][] = $i;
            }
        }
        // 1) mesmo id TMDB do painel = mesma obra (mesmo com nomes diferentes)
        foreach ($v as $i => $x) {
            if ($x['t']) {
                if (isset($porTmdb[$x['t']])) {
                    $unir($porTmdb[$x['t']], $i, true);
                } else {
                    $porTmdb[$x['t']] = $i;
                }
            }
        }
        // 2) mesmo nome normalizado, ano ±1 (em cadeia); sem ano junta com a de id mais próximo
        foreach ($porNome as $grupo) {
            if (count($grupo) < 2) {
                continue;
            }
            $comAno = array_values(array_filter($grupo, fn($i) => $v[$i]['y'] > 0));
            $semAno = array_values(array_filter($grupo, fn($i) => $v[$i]['y'] === 0));
            usort($comAno, fn($a, $b) => [$v[$a]['y'], $a] <=> [$v[$b]['y'], $b]);
            for ($j = 1, $c = count($comAno); $j < $c; $j++) {
                if ($v[$comAno[$j]]['y'] - $v[$comAno[$j - 1]]['y'] <= 1) {
                    $unir($comAno[$j - 1], $comAno[$j]);
                }
            }
            foreach ($semAno as $i) {
                $alvo = null;
                $melhor = PHP_INT_MAX;
                foreach ($comAno ?: $semAno as $o) {
                    if ($o === $i) {
                        continue;
                    }
                    $custo = abs($v[$o]['p'] - $v[$i]['p']);
                    if ($custo < $melhor) {
                        $melhor = $custo;
                        $alvo = $o;
                    }
                }
                if ($alvo !== null) {
                    $unir($alvo, $i);
                }
            }
        }
        $grupos = [];
        for ($i = 0; $i < $n; $i++) {
            $grupos[$achar($i)][] = $i;
        }
        $saida = [];
        foreach ($grupos as $raiz => $membros) {
            // principal: dublada e sem 4K; depois dublada; depois sem 4K; empate = ordem do painel
            usort($membros, fn($a, $b) => [$v[$a]['leg'] || $v[$a]['uhd'] ? ($v[$a]['leg'] ? ($v[$a]['uhd'] ? 3 : 2) : 1) : 0, $a]
                                       <=> [$v[$b]['leg'] || $v[$b]['uhd'] ? ($v[$b]['leg'] ? ($v[$b]['uhd'] ? 3 : 2) : 1) : 0, $b]);
            $p = $membros[0];
            $ano = $v[$p]['y'];
            if (!$ano) {
                foreach ($membros as $m) {
                    if ($v[$m]['y']) {
                        $ano = $v[$m]['y'];
                        break;
                    }
                }
            }
            $tmdbs = [];
            foreach ($membros as $m) {
                if ($v[$m]['t']) {
                    $tmdbs[$v[$m]['t']] = ($tmdbs[$v[$m]['t']] ?? 0) + ($m === $p ? 2 : 1);
                }
            }
            arsort($tmdbs);
            $saida[] = ['membros' => $membros, 'principal' => $p, 'k' => $v[$p]['k'], 'ano' => $ano,
                        'tmdb' => $tmdbs ? (int) array_key_first($tmdbs) : null];
        }
        return $saida;
    }

    /**
     * Grava as versões de um tipo, cria/atualiza os títulos e desativa o que sumiu.
     * @return array{versoes:int, titulos:int, novos:int, sairam:int}
     */
    public static function sincronizarVersoes(int $contaId, string $tipo, array $v): array
    {
        $pdo = Db::pdo();
        if (!$v) {
            throw new RuntimeException("lista vazia do painel ($tipo): nada foi mudado");
        }
        // estado atual
        $st = $pdo->prepare('select painel_id, titulo_id, ativo from play.versao where conta_id = ? and tipo = ?');
        $st->execute([$contaId, $tipo]);
        $tituloDe = [];
        $ativosAntes = 0;
        while ($l = $st->fetch(PDO::FETCH_NUM)) {
            $tituloDe[(int) $l[0]] = $l[1] !== null ? (int) $l[1] : null;
            $ativosAntes += Banco::bool($l[2]) ? 1 : 0;
        }
        $st = $pdo->prepare("select id, nome, nome_norm, ano, tmdb_id, poster, categorias, extract(epoch from adicionado_em)::bigint as adic,
                                    nota_painel, ativo, detalhes_em is not null as com_det
                               from play.titulo where tipo = ?");
        $st->execute([$tipo]);
        $titulos = [];
        $porTmdb = [];
        $porNome = [];
        while ($t = $st->fetch()) {
            $id = (int) $t['id'];
            $titulos[$id] = $t;
            if ($t['tmdb_id'] !== null) {
                $porTmdb[(int) $t['tmdb_id']][] = $id;
            }
            $porNome[$t['nome_norm']][] = $id;
        }

        $grupos = self::agrupar($v);
        // 1ª passada: grupos que já têm título (voto das versões); o maior grupo fica com o id
        usort($grupos, fn($a, $b) => count($b['membros']) <=> count($a['membros']));
        $dono = [];                                         // titulo_id => índice do grupo
        foreach ($grupos as $gi => &$g) {
            $votos = [];
            foreach ($g['membros'] as $m) {
                $t = $tituloDe[$v[$m]['p']] ?? null;
                if ($t !== null && isset($titulos[$t])) {
                    $votos[$t] = ($votos[$t] ?? 0) + 1;
                }
            }
            arsort($votos);
            foreach (array_keys($votos) as $t) {
                if (!isset($dono[$t])) {
                    $g['id'] = $t;
                    $dono[$t] = $gi;
                    break;
                }
            }
        }
        unset($g);
        // 2ª passada: título antigo pelo tmdb ou pelo nome+ano (voltou ao painel, troca de conta)
        foreach ($grupos as $gi => &$g) {
            if (isset($g['id'])) {
                continue;
            }
            $cands = $g['tmdb'] ? ($porTmdb[$g['tmdb']] ?? []) : [];
            foreach ($porNome[$g['k']] ?? [] as $t) {
                $a = $titulos[$t]['ano'] !== null ? (int) $titulos[$t]['ano'] : 0;
                $tt = $titulos[$t]['tmdb_id'] !== null ? (int) $titulos[$t]['tmdb_id'] : null;
                if ((!$a || !$g['ano'] || abs($a - $g['ano']) <= 1) && (!$g['tmdb'] || !$tt || $tt === $g['tmdb'])) {
                    $cands[] = $t;
                }
            }
            foreach ($cands as $t) {
                if (!isset($dono[$t])) {
                    $g['id'] = $t;
                    $dono[$t] = $gi;
                    break;
                }
            }
        }
        unset($g);

        // títulos novos: ids reservados de uma vez
        $semId = array_keys(array_filter($grupos, fn($g) => !isset($g['id'])));
        if ($semId) {
            $ids = $pdo->query('select nextval(pg_get_serial_sequence(\'play.titulo\', \'id\')) from generate_series(1, ' . count($semId) . ')')
                       ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($semId as $j => $gi) {
                $grupos[$gi]['id'] = (int) $ids[$j];
                $grupos[$gi]['novo'] = true;
            }
        }

        // linhas de título (só as que mudaram) e versões
        $novos = [];
        $mudados = [];
        $versoes = [];
        foreach ($grupos as $g) {
            $p = $v[$g['principal']];
            $cats = [];
            $adic = null;
            foreach ($g['membros'] as $m) {
                foreach ($v[$m]['cats'] as $c) {
                    $cats[$c] = true;
                }
                $adic = max($adic ?? 0, $v[$m]['a'] ?? 0) ?: $adic;
                $x = $v[$m];
                $versoes[] = ['p' => $x['p'], 't' => $g['id'], 'n' => $x['n'], 'i' => $x['leg'] ? 'leg' : 'dub', 'u' => $x['uhd'],
                              'e' => $x['e'], 'cat' => $x['cat'], 'tm' => $x['t'], 'c' => $x['c'], 'r' => $x['r'], 'a' => $x['a']];
            }
            $cats = array_keys($cats);
            sort($cats);
            $nome = Texto::limparNome($p['n']) ?: $p['n'];
            $linha = ['id' => $g['id'], 'nome' => $nome, 'k' => Texto::norm($nome) ?: $g['k'], 'ano' => $g['ano'] ?: null,
                      'tmdb' => $g['tmdb'], 'poster' => $p['c'], 'cats' => $cats, 'adic' => $adic, 'nota' => $p['r']];
            if (!empty($g['novo'])) {
                $novos[] = $linha;
                continue;
            }
            $t = $titulos[$g['id']];
            $tmdbAtual = $t['tmdb_id'] !== null ? (int) $t['tmdb_id'] : null;
            $muda = $t['nome'] !== $linha['nome'] || ($t['ano'] !== null ? (int) $t['ano'] : null) !== $linha['ano']
                || ($linha['tmdb'] && $linha['tmdb'] !== $tmdbAtual)
                || (!Banco::bool($t['com_det']) && $t['poster'] !== $linha['poster'])
                || Banco::lerInts($t['categorias']) !== $cats
                || ($t['adic'] !== null ? (int) $t['adic'] : null) !== $linha['adic']
                || ($t['nota_painel'] !== null ? round((float) $t['nota_painel'], 2) : null) !== $linha['nota']
                || !Banco::bool($t['ativo']);
            if ($muda) {
                $linha['novo_tmdb'] = $linha['tmdb'] && $linha['tmdb'] !== $tmdbAtual;
                $mudados[] = $linha;
            }
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("
                insert into play.titulo (id, tipo, nome, nome_norm, ano, tmdb_id, poster, categorias, adicionado_em, nota_painel, ativo, atualizado_em)
                select x.id, ?, x.nome, x.k, x.ano, x.tmdb, x.poster, coalesce(x.cats, '{}'), to_timestamp(x.adic), x.nota, true, now()
                  from jsonb_to_recordset(cast(? as jsonb)) as x(id bigint, nome text, k text, ano int, tmdb int, poster text, cats int[], adic bigint, nota numeric)");
            foreach (array_chunk($novos, self::LOTE) as $lote) {
                $ins->execute([$tipo, Banco::json($lote)]);
            }
            $upd = $pdo->prepare("
                update play.titulo t set
                       nome = x.nome, nome_norm = x.k, ano = x.ano,
                       tmdb_id = coalesce(x.tmdb, t.tmdb_id),
                       detalhes_em = case when x.novo_tmdb then null else t.detalhes_em end,
                       poster = case when t.detalhes_em is null or x.novo_tmdb then x.poster else t.poster end,
                       categorias = coalesce(x.cats, '{}'), adicionado_em = to_timestamp(x.adic), nota_painel = x.nota,
                       ativo = true, atualizado_em = now()
                  from jsonb_to_recordset(cast(? as jsonb)) as x(id bigint, nome text, k text, ano int, tmdb int, novo_tmdb boolean,
                                                                 poster text, cats int[], adic bigint, nota numeric)
                 where t.id = x.id");
            foreach (array_chunk($mudados, self::LOTE) as $lote) {
                $upd->execute([Banco::json($lote)]);
            }
            $ver = $pdo->prepare("
                insert into play.versao (conta_id, tipo, painel_id, titulo_id, nome_painel, idioma, uhd, ext, categoria_id, tmdb_painel,
                                         poster_painel, nota, adicionado_em, ativo, visto_em)
                select ?, ?, x.p, x.t, x.n, x.i, x.u, x.e, x.cat, x.tm, x.c, x.r, to_timestamp(x.a), true, now()
                  from jsonb_to_recordset(cast(? as jsonb)) as x(p int, t bigint, n text, i text, u boolean, e text, cat int, tm int, c text, r numeric, a bigint)
                on conflict (conta_id, tipo, painel_id) do update set
                       titulo_id = excluded.titulo_id, nome_painel = excluded.nome_painel, idioma = excluded.idioma, uhd = excluded.uhd,
                       ext = excluded.ext, categoria_id = excluded.categoria_id, tmdb_painel = excluded.tmdb_painel,
                       poster_painel = excluded.poster_painel, nota = excluded.nota, adicionado_em = excluded.adicionado_em,
                       ativo = true, visto_em = now()
                 where play.versao.visto_em < now() - interval '20 hours' or not play.versao.ativo
                    or (play.versao.titulo_id, play.versao.nome_painel, play.versao.ext, play.versao.categoria_id, play.versao.tmdb_painel,
                        play.versao.poster_painel, play.versao.nota, play.versao.adicionado_em)
                       is distinct from
                       (excluded.titulo_id, excluded.nome_painel, excluded.ext, excluded.categoria_id, excluded.tmdb_painel,
                        excluded.poster_painel, excluded.nota, excluded.adicionado_em)");
            foreach (array_chunk($versoes, self::LOTE) as $lote) {
                $ver->execute([$contaId, $tipo, Banco::json($lote)]);
            }
            // o que sumiu do painel (proteção: lista com menos da metade do que havia = falha do painel)
            $sairam = 0;
            if (count($v) >= $ativosAntes * 0.5) {
                $presentes = array_flip(array_column($v, 'p'));
                $fora = [];
                foreach ($tituloDe as $p => $_) {
                    if (!isset($presentes[$p])) {
                        $fora[] = $p;
                    }
                }
                $off = $pdo->prepare('update play.versao set ativo = false where conta_id = ? and tipo = ? and ativo and painel_id = any(cast(? as int[]))');
                foreach (array_chunk($fora, 5000) as $lote) {
                    $off->execute([$contaId, $tipo, Banco::ints($lote)]);
                }
                $st = $pdo->prepare("update play.titulo t set ativo = false, atualizado_em = now()
                                      where t.tipo = ? and t.ativo
                                        and not exists (select 1 from play.versao v where v.titulo_id = t.id and v.ativo and v.conta_id = ?)");
                $st->execute([$tipo, $contaId]);
                $sairam = $st->rowCount();
            } else {
                Log::info("catálogo $tipo: painel mandou " . count($v) . " de $ativosAntes itens; nada foi desativado");
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['versoes' => count($v), 'titulos' => count($grupos), 'novos' => count($novos), 'sairam' => $sairam];
    }

    // ------------------------------------------------------------ "Avisar quando chegar"

    /**
     * Títulos marcados "avisar quando chegar" que agora estão na lista: o aviso ganha `quando = agora`
     * e o id do título (a tarefa avisos dispara). Compara pelo tmdb e, sem ele, pelo nome (+ ano ±1).
     */
    public static function avisosChegar(): int
    {
        $pdo = Db::pdo();
        $avisos = $pdo->query("select id, perfil_id, alvo from play.aviso where tipo = 'chegar' and status = 'ativo' and quando is null")->fetchAll();
        if (!$avisos) {
            return 0;
        }
        $n = 0;
        $porTmdb = $pdo->prepare('select id, nome from play.titulo where ativo and tipo = ? and tmdb_id = ? order by adicionado_em desc nulls last limit 1');
        foreach ($avisos as $a) {
            $alvo = json_decode((string) $a['alvo'], true) ?: [];
            $tipo = ($alvo['tipo'] ?? 'm') === 's' ? 's' : 'm';
            $t = null;
            if (!empty($alvo['tmdb'])) {
                $porTmdb->execute([$tipo, (int) $alvo['tmdb']]);
                $t = $porTmdb->fetch() ?: null;
            }
            if (!$t && !empty($alvo['nome'])) {
                $id = Indice::compartilhado()->achar($tipo, (string) $alvo['nome'], (int) ($alvo['ano'] ?? 0));
                if ($id) {
                    $t = ['id' => $id, 'nome' => Indice::compartilhado()->nome($id) ?? $alvo['nome']];
                }
            }
            if (!$t) {
                continue;
            }
            $alvo['titulo'] = (int) $t['id'];
            $pdo->prepare("update play.aviso set quando = now(), alvo = cast(? as jsonb), texto = coalesce(texto, ?) where id = ?")
                ->execute([Banco::json($alvo), 'Chegou na lista: ' . $t['nome'], $a['id']]);
            $n++;
        }
        if ($n) {
            Tarefas::forcar('avisos');
        }
        return $n;
    }

    private static function url(mixed $u): ?string
    {
        $u = is_string($u) ? trim($u) : '';
        return preg_match('#^https?://\S+$#', $u) && !preg_match('#/None$|bestv2/None#', $u) ? $u : null;
    }
}
