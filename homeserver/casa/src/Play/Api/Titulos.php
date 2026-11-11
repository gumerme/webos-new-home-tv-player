<?php
declare(strict_types=1);

namespace Casa\Play\Api;

use Casa\Play\Dados\Tmdb;
use Casa\Play\Dados\Xtream;

/** Detalhe do título, temporadas, pós-filme e "tocar" (link direto do painel IPTV para a TV). */
final class Titulos
{
    // ------------------------------------------------------------- detalhe

    public static function titulo(Pedido $p): array
    {
        $perfil = $p->perfil();
        $id = $p->id();
        Cartoes::carregar([$id], $perfil);
        $c = Cartoes::de($id) ?? throw new Erro('título não encontrado', 404);
        $t = Base::linha('select tipo, sinopse, generos::text as generos, detalhes, jw, colecao_id, link_testado_em
                            from play.titulo where id = ?', [$id]);
        $det = Base::json($t['detalhes']);
        $jw = Base::json($t['jw']);
        $generos = Base::lerArr($t['generos']);

        // Pessoas: tabela titulo_pessoa (worker); sem ela, o que veio nos detalhes.
        $elenco = [];
        $diretor = null;
        $criadores = [];
        foreach (Base::linhas('select tp.pessoa_id, tp.papel, tp.personagem, ps.nome, ps.foto
                                 from play.titulo_pessoa tp join play.pessoa ps on ps.tmdb_id = tp.pessoa_id
                                where tp.titulo_id = ? order by tp.ordem, ps.nome', [$id]) as $x) {
            if ($x['papel'] === 'elenco' && count($elenco) < 15) {
                $elenco[] = ['tmdb' => (int) $x['pessoa_id'], 'nome' => $x['nome'], 'personagem' => $x['personagem'], 'foto' => Base::img($x['foto'], 'w185')];
            } elseif ($x['papel'] === 'direcao') {
                $diretor ??= $x['nome'];
            } elseif ($x['papel'] === 'criacao') {
                $criadores[] = $x['nome'];
            }
        }
        if (!$elenco && is_array($e = Base::det($det, 'elenco', 'cast'))) {
            foreach (array_slice($e, 0, 15) as $x) {
                if (is_array($x)) {
                    $elenco[] = ['tmdb' => Base::inteiroOuNulo($x['tmdb'] ?? $x['id'] ?? null), 'nome' => (string) ($x['nome'] ?? $x['name'] ?? ''),
                                 'personagem' => $x['personagem'] ?? $x['character'] ?? null, 'foto' => Base::img($x['foto'] ?? $x['profile_path'] ?? null, 'w185')];
                }
            }
        }
        $diretor ??= self::nomes(Base::det($det, 'diretor', 'direcao'))[0] ?? null;
        $criadores = $criadores ?: self::nomes(Base::det($det, 'criadores', 'criacao', 'created_by'));

        $versoes = self::versoes($id);
        $prog = Base::linha('select versao_id, temporada, episodio, posicao_s, duracao_s, terminado, atualizado_em, tv.nome as tv
                               from play.progresso p left join play.tv tv on tv.id = p.tv_id
                              where p.perfil_id = ? and p.titulo_id = ?', [$perfil, $id]);
        $escolhida = self::escolher($perfil, $id, $versoes, null);
        $temporadas = Base::det($det, 'temporadas', 'number_of_seasons');

        return self::trailer($c['tipo'], $c['tmdb'] ?? null, Cartoes::linha($id)['trailer'] ?? null, $c['nome'] ?? null, $c['ano'] ?? null) + $c + [
            'sinopse'              => $t['sinopse'],
            'generos'              => Base::generos($generos) ?: self::nomes(Base::det($det, 'generos_nomes', 'genres')),
            'duracao_min'          => Base::inteiroOuNulo(Base::det($det, 'duracao_min', 'duracao', 'runtime')),
            'temporadas'           => is_array($temporadas) ? count($temporadas) : Base::inteiroOuNulo($temporadas),
            'classificacao'        => Base::det($det, 'classificacao', 'certificacao'),
            'diretor'              => $diretor,
            'criadores'            => array_values(array_unique($criadores)),
            'elenco'               => $elenco,
            'notas'                => [
                'imdb'        => Base::numeroOuNulo($jw['imdb'] ?? null),
                'imdb_votos'  => Base::inteiroOuNulo($jw['imdb_votos'] ?? null),
                'rotten'      => Base::inteiroOuNulo($jw['rotten'] ?? null),
                'jw_gostaram' => Base::inteiroOuNulo($jw['gostaram'] ?? null),
            ],
            'dias_top10'           => (int) Base::valor("select count(distinct cast(coletado_em at time zone ? as date)) from play.top10 where titulo_id = ?",
                [Base::tz()->getName(), $id]),
            'versoes'              => array_map(fn($v) => ['id' => (int) $v['painel_id'], 'idioma' => $v['idioma'], 'uhd' => Base::b($v['uhd'])], $versoes),
            'versao_escolhida'     => $escolhida ? (int) $escolhida['painel_id'] : null,
            'legenda_pronta'       => (bool) Base::valor('select 1 from play.legenda where titulo_id = ? and arquivo is not null limit 1', [$id]),
            'qualidade_testada_em' => Base::iso($t['link_testado_em']),
            'saga'                 => self::saga($t['colecao_id'] !== null ? (int) $t['colecao_id'] : null, $perfil),
            'similares'            => Cartoes::lista(self::similares($id, $c['tipo'], $det, $generos, 20), $perfil, 20),
            'minha_lista'          => (bool) Base::valor('select 1 from play.lista where perfil_id = ? and titulo_id = ?', [$perfil, $id]),
            'nota_usuario'         => (int) (Base::valor('select valor from play.nota where perfil_id = ? and titulo_id = ?', [$perfil, $id]) ?? 0),
            'retomar'              => self::retomar($c['tipo'], $prog),
        ];
    }

    /** Nomes de uma lista que pode vir como strings ou objetos {nome|name}. */
    private static function nomes(mixed $v): array
    {
        if (is_string($v)) {
            return [$v];
        }
        $out = [];
        foreach ((array) $v as $x) {
            $n = is_array($x) ? ($x['nome'] ?? $x['name'] ?? null) : $x;
            if (is_string($n) && $n !== '') {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** Onde retomar: posição do filme/episódio, ou o próximo episódio quando o último terminou. */
    private static function retomar(string $tipo, ?array $prog): ?array
    {
        if (!$prog) {
            return null;
        }
        $base = ['versao' => Base::inteiroOuNulo($prog['versao_id']), 'tv' => $prog['tv'], 'quando' => Base::iso($prog['atualizado_em'])];
        if (!Base::b($prog['terminado'])) {
            return $base + ['temporada' => Base::inteiroOuNulo($prog['temporada']), 'episodio' => Base::inteiroOuNulo($prog['episodio']),
                            'posicao_s' => (int) $prog['posicao_s']];
        }
        if ($tipo !== 's' || $prog['versao_id'] === null) {
            return null;
        }
        $eps = self::episodios(Base::contaId(), (int) $prog['versao_id']);
        $prox = self::proximo($eps, (int) $prog['temporada'], (int) $prog['episodio']);
        return $prox ? $base + ['temporada' => $prox['temporada'], 'episodio' => $prox['numero'], 'posicao_s' => 0] : null;
    }

    private static function saga(?int $colecao, int $perfil): ?array
    {
        if (!$colecao) {
            return null;
        }
        $nome = Base::valor('select nome from play.colecao where id = ?', [$colecao]);
        $ids = array_map('intval', Base::coluna("select id from play.titulo where colecao_id = ? and ativo and tipo = 'm' order by ano nulls last, id", [$colecao]));
        if ($nome === null || count($ids) < 2) {
            return null;
        }
        // visto = terminado ou Gostei (Base::VISTOS)
        $vistos = (int) Base::valor('select count(distinct titulo_id) from ' . Base::VISTOS . ' x where titulo_id = any(cast(? as bigint[]))',
            [...Base::vistosPar($perfil), Base::arr($ids)]);
        return ['id' => $colecao, 'nome' => $nome, 'itens' => Cartoes::lista($ids, $perfil, 30), 'vistos' => $vistos];
    }

    /**
     * Parecidos que estão na lista: os "similares/recomendações" do TMDB guardados pelo worker,
     * completados por títulos do mesmo tipo com gêneros em comum (os mais votados).
     */
    /**
     * Trailer com idioma (09/11): {trailer_youtube, trailer_legenda}. Prefere o dublado em pt-BR pelos vídeos do TMDB
     * (cache 30 dias); sem TMDB, fica o guardado nos detalhes. trailer_legenda = não é em português (a TV liga a legenda pt-BR).
     */
    public static function trailer(string $tipo, ?int $tmdb, ?string $guardado, ?string $nome = null, ?int $ano = null): array
    {
        // dublado/legendado em pt pelo YouTube quando o TMDB só tem inglês (Dados\Trailer, cache 30 dias)
        $t = \Casa\Play\Dados\Trailer::melhor($tipo, $tmdb, $nome, $ano, $guardado);
        return ['trailer_youtube' => $t['trailer_youtube'], 'trailer_legenda' => $t['trailer_legenda']];
    }

    public static function similares(int $id, string $tipo, array $det, array $generos, int $limite): array
    {
        $tmdb = [];
        foreach (['similares', 'recomendacoes', 'recommendations', 'similar'] as $k) {
            foreach ((array) ($det[$k] ?? []) as $x) {
                $v = is_array($x) ? ($x['tmdb'] ?? $x['id'] ?? null) : $x;
                if (is_numeric($v)) {
                    $tmdb[] = (int) $v;
                }
            }
        }
        $ids = [];
        if ($tmdb) {
            $porTmdb = [];
            foreach (Base::linhas('select id, tmdb_id from play.titulo where tipo = ? and ativo and tmdb_id = any(cast(? as int[]))', [$tipo, Base::arr($tmdb)]) as $x) {
                $porTmdb[(int) $x['tmdb_id']] ??= (int) $x['id'];
            }
            foreach ($tmdb as $t) {
                if (isset($porTmdb[$t]) && $porTmdb[$t] !== $id) {
                    $ids[] = $porTmdb[$t];
                }
            }
        }
        if (count($ids) < $limite && $generos) {
            $ids = array_merge($ids, array_map('intval', Base::coluna("
                select id from play.titulo
                 where tipo = ? and ativo and id <> ? and generos && cast(? as int[])
                 order by cardinality(array(select unnest(generos) intersect select unnest(cast(? as int[])))) desc,
                          coalesce(cast(jw->>'imdb_votos' as numeric), 0) desc
                 limit ?", [$tipo, $id, Base::arr($generos), Base::arr($generos), $limite])));
        }
        return array_slice(array_values(array_unique($ids)), 0, $limite);
    }

    /**
     * GET titulo/por-tmdb?tipo=m|s&tmdb=&xs= — deep link da v10 (open?type=&tmdb=&xs=): acha o título pelo TMDB
     * ou pelo id do painel (stream_id / series_id). -> {id, nome, tipo} ou 404.
     */
    public static function porTmdb(Pedido $p): array
    {
        $tipo = Inicio::tipo($p);
        $tmdb = $p->int('tmdb');
        $xs = $p->int('xs');
        $l = null;
        if ($tmdb !== null && $tmdb > 0) {
            $l = Base::linha('select id, nome, tipo from play.titulo where tipo = ? and tmdb_id = ? and ativo order by id limit 1', [$tipo, $tmdb]);
        }
        if (!$l && $xs !== null && $xs > 0) {
            $l = Base::linha('select t.id, t.nome, t.tipo from play.versao v join play.titulo t on t.id = v.titulo_id and t.ativo
                               where v.tipo = ? and v.painel_id = ? order by (v.conta_id = ?) desc limit 1', [$tipo, $xs, Base::contaId()]);
        }
        if (!$l) {
            throw new Erro('não está na lista', 404);
        }
        return ['id' => (int) $l['id'], 'nome' => $l['nome'], 'tipo' => $l['tipo']];
    }

    // ---------------------------------------------------------- versões

    /** Versões do título na lista ativa (dublada antes, sem 4K antes: a TV 2 não aguenta 4K). */
    public static function versoes(int $titulo): array
    {
        return Base::linhas("select painel_id, idioma, uhd, ext, nome_painel from play.versao
                              where titulo_id = ? and conta_id = ? and ativo
                              order by (idioma = 'dub') desc, uhd, painel_id", [$titulo, Base::contaId()]);
    }

    /** Versão a tocar: a pedida; senão o idioma escolhido pelo perfil; senão a do progresso; senão a primeira. */
    public static function escolher(int $perfil, int $titulo, array $versoes, ?int $pedida): ?array
    {
        if (!$versoes) {
            return null;
        }
        $porId = array_column($versoes, null, 'painel_id');
        if ($pedida !== null) {
            return $porId[$pedida] ?? throw new Erro('versão não encontrada para este título', 404);
        }
        $idioma = Base::valor('select idioma from play.versao_escolhida where perfil_id = ? and titulo_id = ?', [$perfil, $titulo]);
        if ($idioma !== null) {
            foreach ($versoes as $v) {
                if ($v['idioma'] === $idioma) {
                    return $v;
                }
            }
        }
        $doProgresso = Base::valor('select versao_id from play.progresso where perfil_id = ? and titulo_id = ?', [$perfil, $titulo]);
        return ($doProgresso !== null ? ($porId[(int) $doProgresso] ?? null) : null) ?? $versoes[0];
    }

    // ------------------------------------------------------- temporadas

    /** Garante os episódios da série em play.episodio (get_series_info sob demanda, no máximo a cada 6 h). */
    public static function garantirEpisodios(int $conta, int $serie, bool $forcar = false): void
    {
        $fresco = Base::valor("select atualizado_em > now() - interval '6 hours' from play.serie_info where conta_id = ? and serie_id = ?", [$conta, $serie]);
        if (!$forcar && Base::b($fresco)) {
            return;
        }
        try {
            Xtream::serieInfo($conta, $serie, $forcar);
        } catch (\Throwable $e) {
            error_log("play: episódios da série $serie não atualizados: " . $e->getMessage());
        }
    }

    public static function episodios(int $conta, int $serie): array
    {
        return Base::linhas('select ep_id, temporada, numero, nome, sinopse, ext, duracao_s, still
                               from play.episodio where conta_id = ? and serie_id = ?
                              order by temporada, numero', [$conta, $serie]);
    }

    /** Episódio seguinte (lista ordenada por temporada, número). */
    public static function proximo(array $eps, int $temporada, int $episodio): ?array
    {
        foreach ($eps as $e) {
            if ((int) $e['temporada'] > $temporada || ((int) $e['temporada'] === $temporada && (int) $e['numero'] > $episodio)) {
                return ['temporada' => (int) $e['temporada'], 'numero' => (int) $e['numero'], 'nome' => $e['nome'], 'ep_id' => (int) $e['ep_id']] + $e;
            }
        }
        return null;
    }

    public static function temporadas(Pedido $p): array
    {
        $perfil = $p->perfil();
        $id = $p->id();
        $tipo = Base::valor('select tipo from play.titulo where id = ? and ativo', [$id]) ?? throw new Erro('título não encontrado', 404);
        if ($tipo !== 's') {
            throw new Erro('título não é série', 422);
        }
        $conta = Base::contaId();
        $v = self::escolher($perfil, $id, self::versoes($id), $p->int('versao'));
        if (!$v) {
            return ['temporadas' => []];
        }
        self::garantirEpisodios($conta, (int) $v['painel_id'], $p->bool('atualizar'));
        $prog = [];
        foreach (Base::linhas('select temporada, episodio, posicao_s, duracao_s, terminado from play.progresso_ep where perfil_id = ? and titulo_id = ?', [$perfil, $id]) as $x) {
            $prog[$x['temporada'] . ':' . $x['episodio']] = $x;
        }
        $temps = [];
        $eps = self::episodios($conta, (int) $v['painel_id']);
        $tmdbEps = self::tmdbEpisodios(Base::inteiroOuNulo(Base::valor('select tmdb_id from play.titulo where id = ?', [$id])),
            array_unique(array_map(fn($e) => (int) $e['temporada'], $eps)));
        foreach ($eps as $e) {
            $x = $prog[$e['temporada'] . ':' . $e['numero']] ?? null;
            $tm = $tmdbEps[$e['temporada'] . ':' . $e['numero']] ?? [];
            $temps[(int) $e['temporada']][] = [
                'id'        => (int) $e['ep_id'],
                'numero'    => (int) $e['numero'],
                // painel manda "S04E02" ou nada como nome: usa o do TMDB (10/11/2026)
                'nome'      => self::nomeEpisodio($e['nome'], $tm['nome'] ?? null),
                'sinopse'   => trim((string) $e['sinopse']) !== '' ? $e['sinopse'] : ($tm['sinopse'] ?? null),
                'duracao_s' => Base::inteiroOuNulo($e['duracao_s']),
                'still'     => Base::img($tm['still'] ?? null, 'w342') ?? Base::img($e['still'], 'w342'),
                'xt'        => ['id' => (int) $e['ep_id'], 'ext' => (string) ($e['ext'] ?: ($v['ext'] ?: 'mp4'))],
                'progresso' => $x ? [
                    'pct'       => Base::b($x['terminado']) ? 100 : ((int) $x['duracao_s'] > 0 ? min(99, (int) round($x['posicao_s'] * 100 / $x['duracao_s'])) : 0),
                    'terminado' => Base::b($x['terminado']),
                ] : null,
            ];
        }
        $out = [];
        foreach ($temps as $n => $eps) {
            $out[] = ['numero' => $n, 'episodios' => $eps];
        }
        return ['versao' => (int) $v['painel_id'], 'temporadas' => $out];
    }

    /**
     * Imagem e sinopse dos episódios pelo TMDB (09/11/2026): o painel quase nunca manda a imagem do episódio
     * (Strange New Worlds: 3 de 4 temporadas sem nenhuma). Temporada guardada uma semana em play.cache;
     * falha no TMDB não impede a lista (fica a imagem do painel ou o fundo colorido).
     * @return array<string, array{still: ?string, sinopse: ?string}> chave "temporada:numero"
     */
    /** Nome do episódio: o do painel, a não ser que seja vazio ou só o código ("S04E02", "T4 E2", "Episódio 2"). */
    public static function nomeEpisodio(?string $painel, ?string $tmdb): ?string
    {
        $p = trim((string) $painel);
        $codigo = $p === '' || preg_match('/^(s\d+\s*e\d+|t\d+\s*e\d+|epis[oó]dio\s*\d+|episode\s*\d+|\d+)$/iu', $p);
        return $codigo && $tmdb ? $tmdb : ($p !== '' ? $p : $tmdb);
    }

    public static function tmdbEpisodios(?int $tmdb, array $temporadas): array
    {
        if (!$tmdb || !Tmdb::configurado()) {
            return [];
        }
        $saida = [];
        foreach ($temporadas as $n) {
            try {
                $d = Tmdb::cache('tv/' . $tmdb . '/season/' . (int) $n . '?language=pt-BR&include_image_language=pt,null', 7 * 86400,
                    fn(array $d) => ['episodes' => array_map(fn($e) => [
                        'episode_number' => $e['episode_number'] ?? null, 'still_path' => $e['still_path'] ?? null, 'name' => $e['name'] ?? null,
                        'overview' => $e['overview'] ?? null], $d['episodes'] ?? [])]);
            } catch (\Throwable $e) {
                error_log("play: episódios TMDB $tmdb/T$n: " . $e->getMessage());
                continue;
            }
            foreach ($d['episodes'] ?? [] as $e) {
                if (isset($e['episode_number'])) {
                    $saida[(int) $n . ':' . (int) $e['episode_number']] = [
                        'still' => $e['still_path'] ?: null, 'sinopse' => trim((string) $e['overview']) ?: null,
                        'nome' => trim((string) ($e['name'] ?? '')) ?: null];
                }
            }
        }
        return $saida;
    }

    // ---------------------------------------------------------- pós-filme

    /**
     * Pós-filme: a próxima parte da saga primeiro; depois os parecidos que o perfil ainda não começou
     * (as recomendações do próprio perfil sobem).
     */
    public static function apos(Pedido $p): array
    {
        $perfil = $p->perfil();
        $id = $p->id();
        $t = Base::linha('select tipo, generos::text as generos, detalhes, colecao_id, ano from play.titulo where id = ?', [$id])
            ?? throw new Erro('título não encontrado', 404);
        $comecados = array_flip(array_map('intval', Base::coluna('
            select titulo_id from play.progresso where perfil_id = ?
            union select titulo_id from play.historico where perfil_id = ?
            union select titulo_id from play.nota where perfil_id = ?', [$perfil, $perfil, $perfil])));   // Gostei = visto; Não gostei = fora
        $comecados[$id] = true;

        $candidatos = [];
        if ($t['colecao_id'] !== null) {
            $candidatos = array_map('intval', Base::coluna("select id from play.titulo where colecao_id = ? and ativo and tipo = 'm'
                                                             and coalesce(ano, 0) >= coalesce(cast(? as int), 0) and id <> ?
                                                           order by ano nulls last, id limit 2", [(int) $t['colecao_id'], $t['ano'], $id]));
        }
        $rec = Inicio::rec($perfil);
        foreach ((array) ($rec['porque'] ?? []) as $x) {
            if (is_array($x) && (int) ($x['titulo_id'] ?? 0) === $id) {
                $candidatos = array_merge($candidatos, Inicio::ids($x['ids'] ?? []));
            }
        }
        $similares = self::similares($id, (string) $t['tipo'], Base::json($t['detalhes']), Base::lerArr($t['generos']), 30);
        $meus = array_flip(Inicio::ids($rec['recomendados'] ?? []));
        usort($similares, fn($a, $b) => (int) isset($meus[$b]) <=> (int) isset($meus[$a]));
        $candidatos = array_values(array_filter(array_unique(array_merge($candidatos, $similares)), fn($x) => !isset($comecados[$x])));

        $cartoes = Cartoes::lista($candidatos, $perfil, 7);
        if (!$cartoes) {
            return ['sugestao' => null, 'outras' => []];
        }
        $sug = array_shift($cartoes);
        $sug = Titulos::trailer($sug['tipo'], $sug['tmdb'] ?? null, Cartoes::linha($sug['id'])['trailer'] ?? null, $sug['nome'] ?? null, $sug['ano'] ?? null) + $sug;
        $sug['sinopse'] = Cartoes::linha($sug['id'])['sinopse'] ?? null;
        return ['sugestao' => $sug, 'outras' => $cartoes];
    }

    // --------------------------------------------------------------- tocar

    public static function tocar(Pedido $p): array
    {
        $perfil = $p->perfil();
        $id = $p->int('titulo') ?? throw new Erro('informe o título', 422);
        $conta = Base::conta() ?? throw new Erro('nenhuma lista IPTV ativa no painel', 409);
        $t = Base::linha('select tipo, nome, detalhes, tmdb_id from play.titulo where id = ? and ativo', [$id]) ?? throw new Erro('título não encontrado', 404);
        $v = self::escolher($perfil, $id, self::versoes($id), $p->int('versao'))
            ?? throw new Erro('este título saiu da lista', 404);
        $det = Base::json($t['detalhes']);
        $prefs = self::prefsLegenda($perfil, $id);

        if ($t['tipo'] === 'm') {
            $prog = Base::linha('select posicao_s, duracao_s, terminado from play.progresso where perfil_id = ? and titulo_id = ?', [$perfil, $id]);
            $ext = $v['ext'] ?: 'mp4';
            $dur = (int) ($prog['duracao_s'] ?? 0) ?: 60 * (int) (Base::inteiroOuNulo(Base::det($det, 'duracao_min', 'duracao', 'runtime')) ?? 0);
            $r = [
                'url'       => Xtream::linkVideo($conta, 'm', (int) $v['painel_id'], $ext),
                'formato'   => self::formato($ext),
                'versao'    => (int) $v['painel_id'],
                'inicio_s'  => $prog && !Base::b($prog['terminado']) ? (int) $prog['posicao_s'] : 0,
                'duracao_s' => $dur ?: null,
                'abertura'  => null,
                'creditos_s' => Base::creditos('m', $dur),
                'proximo'   => null,
                'temporada' => null,
                'episodio'  => null,
            ];
            $temp = $epN = null;
        } else {
            $serie = (int) $v['painel_id'];
            self::garantirEpisodios($conta['id'], $serie);
            $eps = self::episodios($conta['id'], $serie);
            if (!$eps) {
                throw new Erro('a lista ainda não tem episódios desta série', 404);
            }
            $temp = $p->int('temporada');
            $epN = $p->int('episodio');
            if ($temp === null || $epN === null) {
                [$temp, $epN] = self::ondeParou($perfil, $id, $eps);
            }
            $ep = null;
            foreach ($eps as $e) {
                if ((int) $e['temporada'] === $temp && (int) $e['numero'] === $epN) {
                    $ep = $e;
                    break;
                }
            }
            $ep ?? throw new Erro("episódio T{$temp}:E{$epN} não está na lista", 404);
            $pe = Base::linha('select posicao_s, duracao_s, terminado from play.progresso_ep
                                where perfil_id = ? and titulo_id = ? and temporada = ? and episodio = ?', [$perfil, $id, $temp, $epN]);
            $dur = (int) ($ep['duracao_s'] ?: ($pe['duracao_s'] ?? 0));
            $prox = self::proximo($eps, $temp, $epN);
            $ab = Base::linha('select inicio_s, fim_s from play.abertura where titulo_id = ?', [$id]);
            $ext = $ep['ext'] ?: ($v['ext'] ?: 'mp4');
            $r = [
                'url'        => Xtream::linkVideo($conta, 's', (int) $ep['ep_id'], $ext),
                'formato'    => self::formato($ext),
                'versao'     => $serie,
                'inicio_s'   => $pe && !Base::b($pe['terminado']) ? (int) $pe['posicao_s'] : 0,
                'duracao_s'  => $dur ?: null,
                'abertura'   => $ab ? ['inicio_s' => (int) $ab['inicio_s'], 'fim_s' => (int) $ab['fim_s']] : null,
                'creditos_s' => Base::creditos('s', $dur),
                'proximo'    => $prox ? ['temporada' => $prox['temporada'], 'episodio' => $prox['numero'], 'nome' => $prox['nome']] : null,
                'temporada'  => $temp,
                'episodio'   => $epN,
                'nome_episodio' => $ep['nome'],
            ];
        }
        $legendas = array_map(fn($l) => [
            'id' => (int) $l['id'], 'idioma' => $l['idioma'], 'nome' => $l['nome'], 'url' => '/api/play/legenda/' . (int) $l['id'],
        ], Base::linhas('select id, idioma, nome from play.legenda
                          where titulo_id = ? and temporada is not distinct from cast(? as int) and episodio is not distinct from cast(? as int)
                          order by (idioma like ?) desc, (arquivo is not null) desc, id', [$id, $temp, $epN, 'pt%']));
        $atrasos = $prefs['atrasos'];
        return $r + [
            'nome'         => $t['nome'],
            'tmdb'         => Base::inteiroOuNulo($t['tmdb_id']),
            'xs'           => (int) $v['painel_id'],   // id do painel (série: series_id), para o helper antigo /series/set
            'legendas'     => $legendas,
            'legenda_auto' => $prefs['auto'],
            'prefs'        => [
                'tamanho'  => $prefs['tamanho'],
                'atraso_s' => $legendas ? (float) ($atrasos[(string) $legendas[0]['id']] ?? 0) : 0,
                'atrasos'  => (object) $atrasos,
            ],
        ];
    }

    /** Onde a série parou: o episódio em andamento, o seguinte ao terminado, ou o primeiro. */
    private static function ondeParou(int $perfil, int $titulo, array $eps): array
    {
        $prog = Base::linha('select temporada, episodio, terminado from play.progresso where perfil_id = ? and titulo_id = ?', [$perfil, $titulo]);
        if ($prog && $prog['temporada'] !== null && $prog['episodio'] !== null) {
            if (!Base::b($prog['terminado'])) {
                return [(int) $prog['temporada'], (int) $prog['episodio']];
            }
            $prox = self::proximo($eps, (int) $prog['temporada'], (int) $prog['episodio']);
            if ($prox) {
                return [$prox['temporada'], $prox['numero']];
            }
        }
        return [(int) $eps[0]['temporada'], (int) $eps[0]['numero']];
    }

    public static function formato(string $ext): string
    {
        return match (strtolower($ext)) {
            'm3u8', 'ts' => 'hls',
            'mkv'        => 'mkv',
            default      => 'mp4',
        };
    }

    /** Preferências de legenda do perfil: auto (pt-BR ao tocar), tamanho p|m|g e atrasos por legenda deste título. */
    public static function prefsLegenda(int $perfil, int $titulo): array
    {
        $auto = false;
        $tamanho = 'm';
        $atrasos = [];
        foreach (Base::linhas("select chave, valor from play.preferencia
                                where perfil_id = ? and (chave in ('legenda_auto', 'legenda_tamanho') or chave like ?)",
            [$perfil, 'legenda_atraso:' . $titulo . ':%']) as $x) {
            $v = json_decode((string) $x['valor'], true);
            if ($x['chave'] === 'legenda_auto') {
                $auto = $v === true || $v === 1 || $v === 'pt-br';
            } elseif ($x['chave'] === 'legenda_tamanho') {
                $tamanho = in_array($v, ['p', 'm', 'g'], true) ? $v : 'm';
            } elseif (is_numeric($v)) {
                $atrasos[substr($x['chave'], strlen('legenda_atraso:' . $titulo . ':'))] = round((float) $v, 1);
            }
        }
        return ['auto' => $auto, 'tamanho' => $tamanho, 'atrasos' => $atrasos];
    }
}
