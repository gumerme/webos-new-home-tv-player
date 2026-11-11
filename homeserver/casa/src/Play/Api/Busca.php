<?php
declare(strict_types=1);

namespace Casa\Play\Api;

/**
 * Busca (pg_trgm sobre play.norm), pessoas e canais; "fora" = títulos do TMDB que não estão na lista.
 * Erro de digitação: quando nada contém o texto e o melhor resultado vem por semelhança, devolve "corrigido".
 */
final class Busca
{
    private const PAPEIS = ['elenco' => 'Atuação', 'direcao' => 'Direção', 'criacao' => 'Criação'];

    public static function busca(Pedido $p): array
    {
        $perfil = $p->perfil();
        $q = mb_substr(trim($p->str('q')), 0, 80);
        $vazio = ['q' => $q, 'corrigido' => null, 'pessoas' => [], 'titulos' => [], 'fora' => [], 'canais' => []];
        $n = trim((string) Base::valor('select play.norm(?)', [$q]));
        if (mb_strlen($n) < 2) {
            return $vazio;
        }
        // n só tem [a-z0-9 ]: não precisa escapar % e _ do LIKE.
        $contem = '%' . $n . '%';
        $linhas = Base::linhas("
            select id, nome, tipo, tmdb_id, nome_norm like ? as contem
              from play.titulo
             where ativo and (nome_norm like ? or ? <% nome_norm)
             order by nome_norm = ? desc, nome_norm like ? desc, nome_norm like ? desc,
                      word_similarity(?, nome_norm) desc, coalesce(cast(jw->>'imdb_votos' as numeric), 0) desc
             limit 40", [$contem, $contem, $n, $n, $n . '%', $contem, $n]);
        $algumContem = false;
        foreach ($linhas as $l) {
            $algumContem = $algumContem || Base::b($l['contem']);
        }
        $r = $vazio;
        $r['corrigido'] = $linhas && !$algumContem ? $linhas[0]['nome'] : null;
        $r['titulos'] = Cartoes::lista(array_column($linhas, 'id'), $perfil, 40);
        $r['pessoas'] = self::pessoas($n);
        $r['canais'] = AoVivo::canaisPorIds(array_map('intval', Base::coluna("
            select painel_id from play.canal
             where conta_id = ? and ativo and (nome_norm like ? or ? <% nome_norm)
             order by nome_norm like ? desc, numero nulls last, nome limit 10", [Base::contaId(), $contem, $n, $n . '%'])), $perfil);
        if (mb_strlen($n) >= 3 && !Base::pref($perfil, 'esconder_fora_lista', false)) {
            $tmdb = Base::tmdb('search/multi', ['query' => $q, 'include_adult' => 'false', 'region' => 'BR']);
            $r['fora'] = self::fora($tmdb['results'] ?? [], $perfil, 12);
        }
        return $r;
    }

    private static function pessoas(string $n): array
    {
        return array_map(fn($x) => [
            'tmdb'  => (int) $x['tmdb_id'],
            'nome'  => $x['nome'],
            'foto'  => Base::img($x['foto'], 'w185'),
            'papel' => self::PAPEIS[$x['papel']] ?? null,
            'total' => (int) $x['total'],
        ], Base::linhas("
            select ps.tmdb_id, ps.nome, ps.foto, count(distinct t.id) as total,
                   mode() within group (order by tp.papel) as papel
              from play.pessoa ps
              join play.titulo_pessoa tp on tp.pessoa_id = ps.tmdb_id
              join play.titulo t on t.id = tp.titulo_id and t.ativo
             where ps.nome_norm like ? or ? <% ps.nome_norm
             group by ps.tmdb_id, ps.nome, ps.foto, ps.nome_norm
             order by ps.nome_norm like ? desc, count(distinct t.id) desc
             limit 8", ['%' . $n . '%', $n, $n . '%']));
    }

    /**
     * Resultados do TMDB (search/multi, combined_credits) que não estão na lista -> Cartao na_lista:false.
     * Ordem: popularidade do TMDB.
     */
    public static function fora(array $resultados, int $perfil, int $limite): array
    {
        $itens = [];
        foreach ($resultados as $x) {
            if (!is_array($x)) {
                continue;
            }
            $tipo = match ($x['media_type'] ?? null) { 'movie' => 'm', 'tv' => 's', default => null };
            if ($tipo === null || empty($x['id']) || empty($x['poster_path'])) {
                continue;
            }
            $itens[$tipo . (int) $x['id']] ??= [
                'tipo'   => $tipo,
                'tmdb'   => (int) $x['id'],
                'nome'   => (string) ($x['title'] ?? $x['name'] ?? ''),
                'ano'    => Base::inteiroOuNulo(substr((string) ($x['release_date'] ?? $x['first_air_date'] ?? ''), 0, 4)),
                'poster' => $x['poster_path'],
                'fundo'  => $x['backdrop_path'] ?? null,
                'pop'    => (float) ($x['popularity'] ?? 0),
            ];
        }
        if (!$itens) {
            return [];
        }
        foreach (Base::linhas('select tipo, tmdb_id from play.titulo where ativo and tmdb_id = any(cast(? as int[]))', [Base::arr(array_column($itens, 'tmdb'))]) as $l) {
            unset($itens[$l['tipo'] . (int) $l['tmdb_id']]);
        }
        $itens = array_values($itens);
        usort($itens, fn($a, $b) => $b['pop'] <=> $a['pop']);
        return array_map(fn($x) => Cartoes::fora($x, $perfil), array_slice($itens, 0, $limite));
    }

    /** GET pessoa/{tmdb}: o que está na lista com essa pessoa + o que não está (TMDB). */
    public static function pessoa(Pedido $p): array
    {
        $perfil = $p->perfil();
        $tmdb = $p->id('tmdb');
        $ps = Base::linha('select nome, foto from play.pessoa where tmdb_id = ?', [$tmdb]);
        if (!$ps) {
            $t = Base::tmdb('person/' . $tmdb, [], 7 * 86400);
            if (!$t || empty($t['name'])) {
                throw new Erro('pessoa não encontrada', 404);
            }
            $ps = ['nome' => $t['name'], 'foto' => $t['profile_path'] ?? null];
        }
        $ids = array_map('intval', Base::coluna('
            select t.id from play.titulo_pessoa tp join play.titulo t on t.id = tp.titulo_id and t.ativo
             where tp.pessoa_id = ? group by t.id, t.ano order by t.ano desc nulls last, t.id desc limit 80', [$tmdb]));
        $creditos = Base::tmdb('person/' . $tmdb . '/combined_credits', [], 7 * 86400) ?? [];
        $diretor = array_filter((array) ($creditos['crew'] ?? []), fn($c) => is_array($c) && in_array($c['job'] ?? '', ['Director', 'Creator'], true));
        return [
            'nome'    => $ps['nome'],
            'foto'    => Base::img($ps['foto'], 'w342'),
            'titulos' => Cartoes::lista($ids, $perfil, 80),
            'fora'    => self::fora(array_merge((array) ($creditos['cast'] ?? []), array_values($diretor)), $perfil, 30),
        ];
    }
}
