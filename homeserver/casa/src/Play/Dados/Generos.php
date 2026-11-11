<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Gêneros pelo JustWatch (decisão 08/11/2026): filmes e séries são classificados pelos gêneros do
 * JustWatch, não pelas categorias do painel IPTV (do painel ficam só Lançamentos, 4K e Cinema).
 *
 *  - Fonte: `play.titulo.jw->'g'` = shortNames do JustWatch (ex. ["drm","cmy"]), gravados por qualquer consulta
 *    ao JustWatch (Top 10, notas do recomendador) e pela tarefa "generos", que percorre o catálogo todo.
 *    `g: []` = o JustWatch não achou o título (ou não tem gênero) → vale o TMDB traduzido (DE_TMDB).
 *  - Sem `g` ainda (tarefa não passou): também vale o TMDB traduzido.
 */
final class Generos
{
    /** Gêneros do JustWatch Brasil (shortName => nome). "eur" (Made in Europe) fica de fora. */
    public const NOMES = [
        'act' => 'Ação e aventura', 'ani' => 'Animação', 'cmy' => 'Comédia', 'crm' => 'Crime', 'doc' => 'Documentário',
        'drm' => 'Drama', 'fml' => 'Família', 'fnt' => 'Fantasia', 'hst' => 'História', 'hrr' => 'Terror',
        'msc' => 'Música', 'trl' => 'Mistério e suspense', 'rma' => 'Romance', 'scf' => 'Ficção científica',
        'spt' => 'Esporte', 'war' => 'Guerra', 'wsn' => 'Faroeste', 'rly' => 'Reality',
    ];

    /** Gênero TMDB (filmes e TV) => gêneros JustWatch equivalentes (para quem o JustWatch não classificou). */
    public const DE_TMDB = [
        28 => ['act'], 12 => ['act'], 10759 => ['act'], 16 => ['ani'], 35 => ['cmy'], 80 => ['crm'], 99 => ['doc'],
        18 => ['drm'], 10766 => ['drm'], 10751 => ['fml'], 10762 => ['fml'], 14 => ['fnt'], 10765 => ['scf', 'fnt'],
        878 => ['scf'], 27 => ['hrr'], 36 => ['hst'], 10402 => ['msc'], 9648 => ['trl'], 53 => ['trl'],
        10749 => ['rma'], 10752 => ['war'], 10768 => ['war'], 37 => ['wsn'], 10764 => ['rly'], 10767 => ['rly'],
    ];

    /** Categorias do painel que continuam (as outras viram gêneros do JustWatch). */
    public const PAINEL_MANTIDAS = '/lan[cç]amento|recente|\b4k\b|cinema/iu';

    private const LOTE_S = 240;
    private const PAUSA_S = 2;           // entre consultas ao JustWatch (12 títulos cada)

    /** Gêneros efetivos de um título (puro): os do JustWatch; sem eles, o TMDB traduzido. */
    public static function doTitulo(?array $jwG, array $tmdb): array
    {
        $jw = array_values(array_filter((array) $jwG, fn($g) => isset(self::NOMES[$g])));
        if ($jw) {
            return $jw;
        }
        $saida = [];
        foreach ($tmdb as $g) {
            foreach (self::DE_TMDB[(int) $g] ?? [] as $c) {
                $saida[$c] = true;
            }
        }
        return array_keys($saida);
    }

    /** Ids TMDB que traduzem para um gênero JustWatch. */
    public static function tmdbDe(string $codigo): array
    {
        $ids = [];
        foreach (self::DE_TMDB as $t => $cs) {
            if (in_array($codigo, $cs, true)) {
                $ids[] = $t;
            }
        }
        return $ids;
    }

    /** Pesos do perfil (TMDB id => peso) => pesos por gênero JustWatch, normalizados (puro). */
    public static function pesosDoPerfil(array $pesosTmdb): array
    {
        $p = [];
        foreach ($pesosTmdb as $g => $w) {
            if ($w <= 0) {
                continue;
            }
            foreach (self::DE_TMDB[(int) $g] ?? [] as $c) {
                $p[$c] = max($p[$c] ?? 0, (float) $w);    // max: 28 e 12 (ação, aventura) não somam em dobro
            }
        }
        arsort($p);
        return $p;
    }

    /**
     * Filtro SQL "título t está no gênero": [fragmento, parâmetros]. Usa jw->'g' quando o JustWatch classificou,
     * senão os gêneros TMDB traduzidos.
     */
    public static function filtro(string $codigo, string $t = 't'): array
    {
        return ["((jsonb_array_length(coalesce($t.jw->'g', '[]'::jsonb)) > 0 and $t.jw->'g' @> jsonb_build_array(cast(? as text)))
                  or (jsonb_array_length(coalesce($t.jw->'g', '[]'::jsonb)) = 0 and $t.generos && cast(? as int[])))",
                [$codigo, Banco::ints(self::tmdbDe($codigo))]];
    }

    /** VALUES (tmdb, codigo) para SQL (só constantes da classe). */
    public static function sqlValores(): string
    {
        $v = [];
        foreach (self::DE_TMDB as $t => $cs) {
            foreach ($cs as $c) {
                $v[] = '(' . (int) $t . ", '" . $c . "')";
            }
        }
        return implode(', ', $v);
    }

    /** Totais por gênero JustWatch de um tipo (codigo => n), só títulos ativos. */
    public static function totais(string $tipo): array
    {
        $st = Db::pdo()->prepare("
            select g, count(distinct id) as n from (
                select t.id, jsonb_array_elements_text(t.jw->'g') as g
                  from play.titulo t
                 where t.tipo = ? and t.ativo and jsonb_array_length(coalesce(t.jw->'g', '[]'::jsonb)) > 0
                union all
                select t.id, m.g
                  from play.titulo t cross join unnest(t.generos) tg
                  join (values " . self::sqlValores() . ") as m(tmdb, g) on m.tmdb = tg
                 where t.tipo = ? and t.ativo and jsonb_array_length(coalesce(t.jw->'g', '[]'::jsonb)) = 0
            ) x group by g");
        $st->execute([$tipo, $tipo]);
        $r = [];
        foreach ($st->fetchAll() as $l) {
            if (isset(self::NOMES[$l['g']])) {
                $r[$l['g']] = (int) $l['n'];
            }
        }
        return $r;
    }

    // ------------------------------------------------------------ tarefa "generos"

    /**
     * Percorre o catálogo buscando no JustWatch os títulos sem `jw->'g'` (assistidos/lista primeiro, depois os
     * mais novos), 12 por consulta, com pausa. Não achou = `g: []` (vale o TMDB).
     */
    public static function tarefa(): array
    {
        $pdo = Db::pdo();
        $ini = time();
        $feitos = 0;
        $achados = 0;
        $sel = $pdo->prepare("
            select t.id, t.tipo, t.nome, t.ano, t.tmdb_id, t.detalhes->>'nome_tmdb' as nome_tmdb, t.detalhes->>'nome_original' as original
              from play.titulo t
             where t.ativo and not jsonb_exists(coalesce(t.jw, '{}'::jsonb), 'g')
             order by (exists (select 1 from play.historico h where h.titulo_id = t.id)
                       or exists (select 1 from play.progresso p where p.titulo_id = t.id)
                       or exists (select 1 from play.lista l where l.titulo_id = t.id)) desc,
                      (t.tmdb_id is not null) desc, t.adicionado_em desc nulls last, t.id desc
             limit 48");
        $up = $pdo->prepare("update play.titulo set jw = cast(? as jsonb), jw_em = now() where id = ?");
        $marca = $pdo->prepare("update play.titulo set jw = coalesce(jw, '{}'::jsonb) || '{\"g\": []}'::jsonb, jw_em = now() where id = ?");
        do {
            $sel->execute();
            $lote = $sel->fetchAll();
            if (!$lote) {
                break;
            }
            foreach (array_chunk($lote, 12) as $doze) {
                $pedidos = array_map(fn($t) => ['chave' => (string) $t['id'], 'tipo' => $t['tipo'],
                    'tmdb' => $t['tmdb_id'] !== null ? (int) $t['tmdb_id'] : null, 'nome' => (string) ($t['nome_tmdb'] ?: $t['nome']),
                    'original' => $t['original'], 'ano' => $t['ano'] !== null ? (int) $t['ano'] : null], $doze);
                $r = JustWatch::info($pedidos);             // falha = exceção: a tarefa para e volta mais tarde
                foreach ($r as $chave => $v) {
                    if ($v !== null && isset($v['notas']['g'])) {
                        $up->execute([Banco::json($v['notas']), (int) $chave]);
                        $achados++;
                    } else {
                        $marca->execute([(int) $chave]);
                    }
                    $feitos++;
                }
                sleep(self::PAUSA_S);
                if (Tarefas::ceder()) {
                    break 2;
                }
            }
        } while (time() - $ini < self::LOTE_S && !Tarefas::parar());
        $faltam = (int) $pdo->query("select count(*) from play.titulo where ativo and not jsonb_exists(coalesce(jw, '{}'::jsonb), 'g')")->fetchColumn();
        return ['msg' => "$feitos título(s), $achados no JustWatch; faltam $faltam", 'proxima' => $faltam ? time() + 10 : null];
    }
}
