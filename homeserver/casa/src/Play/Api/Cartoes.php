<?php
declare(strict_types=1);

namespace Casa\Play\Api;

/**
 * Montagem do "Cartao" (todo item de fileira) em lote: uma consulta para todos os ids da tela,
 * com o progresso do perfil, a TV onde parou e as posições no Top 10 atual.
 */
final class Cartoes
{
    /** @var array<int, array> linhas cruas por id (do perfil carregado) */
    private static array $linhas = [];
    private static ?int $perfil = null;
    /** @var array<int, array> avisos "chegar" por perfil */
    private static array $avisos = [];

    private const SQL = "
        select t.id, t.tipo, t.nome, t.ano, t.tmdb_id, t.poster, t.fundo, t.qualidade, t.sinopse,
               t.generos::text as generos, t.jw->>'imdb' as imdb, t.adicionado_em, t.colecao_id, t.ativo,
               coalesce(t.detalhes->>'trailer_youtube', t.detalhes->>'trailer', t.detalhes->>'youtube') as trailer,
               p.posicao_s as p_pos, p.duracao_s as p_dur, p.temporada as p_temp, p.episodio as p_ep,
               p.terminado as p_term, p.atualizado_em as p_quando, p.versao_id as p_versao, tv.nome as p_tv,
               t.detalhes->>'classificacao' as classificacao, t.detalhes->>'duracao_min' as duracao_min,
               t.detalhes->>'temporadas' as temporadas,
               (l.titulo_id is not null) as minha_lista, xv.painel_id as xt_id, xv.ext as xt_ext
          from play.titulo t
          left join play.progresso p on p.titulo_id = t.id and p.perfil_id = ?
          left join play.tv tv on tv.id = p.tv_id
          left join play.lista l on l.titulo_id = t.id and l.perfil_id = ?
          left join play.versao_escolhida ve on ve.titulo_id = t.id and ve.perfil_id = ?
          left join lateral (
                select v.painel_id, v.ext from play.versao v
                 where v.titulo_id = t.id and t.tipo = 'm' and v.conta_id = ? and v.ativo
                 order by (v.idioma = coalesce(ve.idioma, 'dub')) desc, v.uhd, v.painel_id limit 1) xv on true
         where t.id = any(cast(? as bigint[]))";

    /** Filme leva "xt" (stream do painel) para a TV tocar sem o homeserver; série leva nos episódios. */

    /** Carrega (uma consulta) os ids que ainda não estão em memória. */
    public static function carregar(iterable $ids, int $perfil): void
    {
        if (self::$perfil !== $perfil) {
            self::$linhas = [];
            self::$perfil = $perfil;
        }
        $faltam = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !array_key_exists($id, self::$linhas)) {
                $faltam[$id] = true;
            }
        }
        if (!$faltam) {
            return;
        }
        foreach (array_keys($faltam) as $id) {
            self::$linhas[$id] = null;
        }
        foreach (Base::linhas(self::SQL, [$perfil, $perfil, $perfil, Base::contaId(), Base::arr(array_keys($faltam))]) as $l) {
            self::$linhas[(int) $l['id']] = $l;
        }
    }

    /** Linha crua (ou null se não existe / fora do catálogo). */
    public static function linha(int $id): ?array
    {
        $l = self::$linhas[$id] ?? null;
        return $l && Base::b($l['ativo']) ? $l : null;
    }

    /** Um cartão (precisa de carregar() antes). */
    public static function de(int $id, ?string $selo = null): ?array
    {
        $l = self::linha($id);
        if (!$l) {
            return null;
        }
        $c = self::montar($l, Top10::porTitulo($id));
        if ($selo !== null) {
            $c['selo'] = $selo;
        }
        return $c;
    }

    /** Cartões na ordem dos ids, sem repetidos e sem os que saíram do catálogo. */
    public static function lista(iterable $ids, int $perfil, int $limite = 40): array
    {
        $ids = is_array($ids) ? $ids : iterator_to_array($ids, false);
        self::carregar($ids, $perfil);
        $out = [];
        $vistos = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($vistos[$id]) || count($out) >= $limite) {
                continue;
            }
            $vistos[$id] = true;
            $c = self::de($id);
            if ($c) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** Cartão a partir da linha (puro: usado nos testes). */
    public static function montar(array $l, array $plataformas = []): array
    {
        $c = [
            'id'          => (int) $l['id'],
            'tmdb'        => Base::inteiroOuNulo($l['tmdb_id'] ?? null),
            'tipo'        => (string) $l['tipo'],
            'nome'        => (string) $l['nome'],
            'ano'         => Base::inteiroOuNulo($l['ano'] ?? null),
            'poster'      => Base::img($l['poster'] ?? null, 'w342'),
            'fundo'       => Base::img($l['fundo'] ?? null, 'w780'),
            'imdb'        => Base::numeroOuNulo($l['imdb'] ?? null),
            'qualidade'   => in_array($l['qualidade'] ?? null, ['4k', 'fhd', 'hd', 'sd'], true) ? $l['qualidade'] : null,
            'na_lista'    => true,
            'plataformas' => $plataformas,
            'progresso'   => self::progresso($l),
            'selo'        => null,
            'minha_lista' => Base::b($l['minha_lista'] ?? false),
        ];
        if (!empty($l['xt_id'])) {
            $c['xt'] = ['id' => (int) $l['xt_id'], 'ext' => (string) ($l['xt_ext'] ?: 'mp4')];
        }
        return $c;
    }

    /**
     * Progresso do perfil no título. Filme terminado não tem progresso; série com o episódio terminado
     * mostra "próximo" (a TV toca o episódio seguinte). Menos de 30 s não conta (regra da v10).
     */
    public static function progresso(array $l): ?array
    {
        if (($l['p_quando'] ?? null) === null) {
            return null;
        }
        $pos = (int) $l['p_pos'];
        $dur = (int) $l['p_dur'];
        $terminado = Base::b($l['p_term'] ?? false);
        $base = [
            'temporada' => Base::inteiroOuNulo($l['p_temp'] ?? null),
            'episodio'  => Base::inteiroOuNulo($l['p_ep'] ?? null),
            'tv'        => $l['p_tv'] ?? null,
            'quando'    => Base::iso($l['p_quando']),
        ];
        if ($terminado) {
            return $l['tipo'] === 's' ? ['pct' => 100, 'restante_s' => 0] + $base + ['proximo' => true] : null;
        }
        if ($pos < 30) {
            return null;
        }
        return [
            'pct'        => $dur > 0 ? min(99, max(1, (int) round($pos * 100 / $dur))) : 0,
            'restante_s' => $dur > 0 ? max(0, $dur - $pos) : null,
        ] + $base + ['proximo' => false];
    }

    /** Título que não está na lista (Top 10, busca TMDB): apagado na TV, com "Avisar". */
    public static function fora(array $x, ?int $perfil = null): array
    {
        $tmdb = Base::inteiroOuNulo($x['tmdb'] ?? $x['tmdb_id'] ?? null);
        $c = [
            'id'          => null,
            'tmdb'        => $tmdb,
            'tipo'        => (string) ($x['tipo'] ?? 'm'),
            'nome'        => (string) ($x['nome'] ?? ''),
            'ano'         => Base::inteiroOuNulo($x['ano'] ?? null),
            'poster'      => Base::img($x['poster'] ?? null, 'w342'),
            'fundo'       => Base::img($x['fundo'] ?? null, 'w780'),
            'imdb'        => Base::numeroOuNulo($x['imdb'] ?? null),
            'qualidade'   => null,
            'na_lista'    => false,
            'plataformas' => $x['plataformas'] ?? [],
            'progresso'   => null,
            'selo'        => null,
            'minha_lista' => false,
        ];
        if ($perfil !== null && $tmdb !== null) {
            $c['aviso_id'] = self::avisosChegar($perfil)[$c['tipo'] . $tmdb] ?? null;
        }
        return $c;
    }

    /** Avisos "avisar quando chegar" ativos do perfil: "m123" => id do aviso. */
    private static function avisosChegar(int $perfil): array
    {
        if (!isset(self::$avisos[$perfil])) {
            self::$avisos[$perfil] = [];
            foreach (Base::linhas("select id, alvo->>'tmdb' as tmdb, coalesce(alvo->>'tipo', 'm') as tipo
                                     from play.aviso where perfil_id = ? and tipo = 'chegar' and status = 'ativo'", [$perfil]) as $a) {
                self::$avisos[$perfil][$a['tipo'] . (int) $a['tmdb']] = (int) $a['id'];
            }
        }
        return self::$avisos[$perfil];
    }

    /** Ids da Minha lista do perfil (para "na minha lista" sem outra consulta por cartão). */
    public static function minhaLista(int $perfil, int $limite = 200): array
    {
        return array_map('intval', Base::coluna('select titulo_id from play.lista where perfil_id = ? order by adicionado_em desc limit ?', [$perfil, $limite]));
    }
}
