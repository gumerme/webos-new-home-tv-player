<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use Casa\Db;

/**
 * Gosto da Netflix (08/11/2026): o usuário baixa a "Atividade de visualização" (NetflixViewingHistory.csv,
 * colunas Title,Date) e o homeserver transforma em sinais do recomendador. Sem senha nem acesso à conta.
 *
 *  - lerCsv/agrupar/peso: puros (testados). Episódios "Série: Temporada 1: Ep" viram a série; prefixo repetido
 *    (novela sem temporada) também; o resto é filme.
 *  - importar: identifica cada obra no TMDB e grava `play.preferencia (perfil, 'netflix')`:
 *    {em, linhas, obras:[{n, t, tmdb, eps, dias, pri, ult, maxdia}]}. Reimportar substitui.
 *  - O recomendador lê isso (Recomendador::sinaisNetflix): o que está na lista IPTV conta como já visto; o que não
 *    está entra como sinal externo (traços pelo TMDB).
 */
final class Netflix
{
    /** Segmento que marca episódio: "Temporada 2", "Parte 1", "Minissérie", "Livro 3"... */
    private const MARCA = '/^(temporada|parte|volume|vol\.|livro|minissérie|minisserie|série limitada|serie limitada|coleção|colecao|capítulo|capitulo|episódio|episodio|season|part|limited series|book|chapter|episode)\b/iu';

    /** CSV da Netflix -> [{t, d:'Y-m-d'}] (puro). Datas M/D/AA (padrão do arquivo) ou D/M/AAAA. */
    public static function lerCsv(string $txt): array
    {
        $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt) ?? $txt;
        $saida = [];
        foreach (preg_split('/\r\n|\n|\r/', $txt) ?: [] as $i => $linha) {
            if (trim($linha) === '') {
                continue;
            }
            $c = str_getcsv($linha, ',', '"', '');
            if ($i === 0 && strcasecmp(trim((string) ($c[0] ?? '')), 'Title') === 0) {
                continue;
            }
            $t = trim((string) ($c[0] ?? ''));
            $d = self::data(trim((string) ($c[1] ?? '')));
            if ($t !== '' && $d !== null) {
                $saida[] = ['t' => $t, 'd' => $d];
            }
        }
        return $saida;
    }

    private static function data(string $s): ?string
    {
        if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})$#', $s, $m)) {
            return null;
        }
        [$a, $b, $ano] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $ano += $ano < 100 ? 2000 : 0;
        [$mes, $dia] = $a > 12 ? [$b, $a] : [$a, $b];       // a Netflix usa M/D; se não couber, é D/M
        return checkdate($mes, $dia, $ano) ? sprintf('%04d-%02d-%02d', $ano, $mes, $dia) : null;
    }

    /**
     * Linhas -> obras (puro): [{n, t:'s'|'m', eps, dias, pri, ult, maxdia}], mais assistidas primeiro.
     */
    public static function agrupar(array $linhas): array
    {
        $prefixos = [];
        foreach ($linhas as $l) {
            $seg = explode(': ', $l['t']);
            if (count($seg) >= 2) {
                $prefixos[$seg[0]][$l['t']] = true;
            }
        }
        $obras = [];
        foreach ($linhas as $l) {
            $seg = explode(': ', $l['t']);
            $nome = null;
            foreach ($seg as $i => $s) {
                if ($i >= 1 && preg_match(self::MARCA, $s)) {
                    $nome = implode(': ', array_slice($seg, 0, $i));
                    break;
                }
            }
            if ($nome === null && count($seg) >= 2 && count($prefixos[$seg[0]] ?? []) >= 3) {
                $nome = $seg[0];                                // novela/série sem "Temporada": prefixo repetido
            }
            $tipo = $nome !== null ? 's' : 'm';
            $nome ??= $l['t'];
            $k = $tipo . '|' . Texto::norm($nome);
            $o = &$obras[$k];
            $o ??= ['n' => $nome, 't' => $tipo, 'eps' => 0, 'dias' => [], 'pri' => $l['d'], 'ult' => $l['d']];
            $o['eps']++;
            $o['dias'][$l['d']] = ($o['dias'][$l['d']] ?? 0) + 1;
            $o['pri'] = min($o['pri'], $l['d']);
            $o['ult'] = max($o['ult'], $l['d']);
            unset($o);
        }
        $saida = [];
        foreach ($obras as $o) {
            $saida[] = ['n' => $o['n'], 't' => $o['t'], 'eps' => $o['eps'], 'dias' => count($o['dias']),
                        'pri' => $o['pri'], 'ult' => $o['ult'], 'maxdia' => max($o['dias'])];
        }
        usort($saida, fn($a, $b) => [$b['eps'], $b['ult']] <=> [$a['eps'], $a['ult']]);
        return $saida;
    }

    /**
     * Peso do sinal (puro). Recência com meia-vida de 1 ano (piso 0,15: gosto antigo ainda conta um pouco).
     * Série: 1 episódio = experimentou (fraco); muitos episódios e maratona = gostou. Filme visto em dias
     * diferentes = reviu (forte).
     */
    public static function peso(array $o, int $agora): float
    {
        // Ponto de partida, não verdade permanente: metade do peso de um sinal do Player e meia-vida de 4 meses
        // (o Player usa 14 dias), então em poucas semanas de uso o que se assiste aqui passa a mandar.
        $idade = max(0, $agora - (int) strtotime($o['ult'] . ' 12:00:00')) / 86400;
        $rec = 0.5 * max(0.15, 0.5 ** ($idade / 120));
        if ($o['t'] === 's') {
            $eps = (int) $o['eps'];
            $engaj = $eps <= 1 ? 0.2 : min(1.6, 0.55 + 0.27 * log($eps));
            if ((int) $o['maxdia'] >= 4) {
                $engaj *= 1.15;                                 // maratona
            }
            if ((int) $o['dias'] >= 10) {
                $engaj *= 1.1;                                  // voltou muitas vezes
            }
        } else {
            $engaj = (int) $o['dias'] >= 2 ? 1.3 : 0.9;
        }
        return round($rec * $engaj, 4);
    }

    /** Identifica as obras no TMDB e grava a preferência 'netflix' do perfil. Devolve o resumo. */
    public static function importar(int $perfil, string $csv, ?callable $progresso = null): array
    {
        $linhas = self::lerCsv($csv);
        if (!$linhas) {
            throw new \RuntimeException('arquivo sem linhas no formato da Netflix (Title,Date)');
        }
        $obras = self::agrupar($linhas);
        $achadas = 0;
        foreach ($obras as $i => &$o) {
            $o['tmdb'] = null;
            // nome inteiro; não achou, encurta ("Série: Coleção: Subtítulo" -> "Série: Coleção" -> "Série")
            $seg = explode(': ', $o['n']);
            $nomes = [];
            for ($k = count($seg); $k >= 1; $k--) {
                $nomes[] = implode(': ', array_slice($seg, 0, $k));
            }
            foreach ($nomes as $nome) {
                foreach ([$o['t'], $o['t'] === 's' ? 'm' : 's'] as $tipo) {
                    try {
                        $id = Tmdb::buscarId($tipo, $nome, 0);
                    } catch (\Throwable $e) {
                        $id = null;
                    }
                    if ($id) {
                        [$o['tmdb'], $o['t']] = [$id, $tipo];
                        $achadas++;
                        break 2;
                    }
                }
            }
            if ($progresso && $i % 25 === 0) {
                $progresso($i + 1, count($obras));
            }
        }
        unset($o);
        $dados = ['em' => date('c'), 'linhas' => count($linhas), 'obras' => $obras];
        Db::pdo()->prepare("insert into play.preferencia (perfil_id, chave, valor) values (?, 'netflix', cast(? as jsonb))
                            on conflict (perfil_id, chave) do update set valor = excluded.valor")
            ->execute([$perfil, Banco::json($dados)]);
        return ['linhas' => count($linhas), 'obras' => count($obras), 'achadas' => $achadas,
                'series' => count(array_filter($obras, fn($o) => $o['t'] === 's')), 'nao_achadas' => array_column(
                    array_filter($obras, fn($o) => !$o['tmdb']), 'n')];
    }

    /** Preferência gravada (ou null). */
    public static function doPerfil(int $perfil): ?array
    {
        $st = Db::pdo()->prepare("select valor from play.preferencia where perfil_id = ? and chave = 'netflix'");
        $st->execute([$perfil]);
        $v = $st->fetchColumn();
        $d = $v ? json_decode((string) $v, true) : null;
        return is_array($d) && !empty($d['obras']) ? $d : null;
    }
}
