<?php
declare(strict_types=1);

/**
 * Testes de fumaça com rede de verdade (sem banco), na máquina de desenvolvimento:
 *   php -d extension=openssl tests/play/fumaca.php [--catalogo]
 * Segredos vêm do config.js da v10 (PLAY_CONFIG_JS) só em memória; nada de usuário/senha/token é impresso.
 * --catalogo baixa as listas do painel UMA vez (uma de cada vez) e mostra só estatísticas da junção
 * e quanto do Top 10 de hoje casa com a lista.
 */

require_once __DIR__ . '/_base.php';
ini_set('memory_limit', '1024M');

use Casa\Play\Dados\Catalogo;
use Casa\Play\Dados\Http;
use Casa\Play\Dados\Indice;
use Casa\Play\Dados\Jogos;
use Casa\Play\Dados\JustWatch;
use Casa\Play\Dados\Legendas;
use Casa\Play\Dados\Texto;
use Casa\Play\Dados\Tmdb;
use Casa\Play\Dados\Top10;
use Casa\Play\Dados\Xtream;

$c = segredosV10();
Casa\Play\Dados\Banco::$semBanco = true;         // sem Postgres aqui: cache TMDB/JustWatch desligado
putenv('TMDB_TOKEN=' . $c['tmdbKey']);
$conta = ['id' => 0, 'nome' => 'v10', 'servidor' => rtrim($c['server'], '/'), 'usuario' => $c['username'], 'senha' => $c['password']];
$t = fn() => microtime(true);
$passo = function (string $nome, callable $f) use ($t) {
    $ini = $t();
    try {
        $msg = $f();
        printf("ok   %-22s %5.2f s  %s\n", $nome, $t() - $ini, $msg);
    } catch (Throwable $e) {
        printf("ERRO %-22s %5.2f s  %s\n", $nome, $t() - $ini, Http::mascarar($e->getMessage()));
    }
};

$passo('painel: conta', function () use ($conta) {
    $i = Xtream::informacoes($conta);
    return sprintf('status %s, vence %s, %d ms', $i['status'], $i['vence_em'] ? date('d/m/Y', $i['vence_em']) : '-', $i['ms']);
});
$top = null;
$passo('JustWatch: Top 10 nfx', function () use (&$top) {
    $top = JustWatch::lerTop10(JustWatch::post(JustWatch::consultaTop10(['nfx' => 'nfx', 'prv' => 'prv', 'dnp' => 'dnp', 'mxx' => 'mxx', 'gop' => 'gop'])),
        ['nfx', 'prv', 'dnp', 'mxx', 'gop']);
    $n = $top['nfx']['m'][0];
    return sprintf('%d filmes / %d séries; nº1 filme: %s (%d) tmdb %d', count($top['nfx']['m']), count($top['nfx']['s']), $n['nome'], $n['ano'], $n['tmdb']);
});
$passo('TMDB: detalhes', function () {
    $d = Tmdb::get(Tmdb::detalhesUrl('m', 157336));
    $e = Tmdb::enxuto($d, 'm');
    return sprintf('%s · %d min · class. %s · trailer %s · %d pessoas · imdb %s', $e['detalhes']['nome_tmdb'], $e['detalhes']['duracao_min'],
        $e['detalhes']['classificacao'] ?? '-', $e['detalhes']['trailer'] ?? '-', count($e['pessoas']), $e['imdb']);
});
$passo('TMDB: busca série', function () {
    $id = Tmdb::buscarId('s', 'Meu Nome é Farah', 2025);
    return "Meu Nome é Farah → tmdb " . ($id ?? 'nenhum');
});
$passo('Top10: nome pt-BR TMDB', function () use (&$top) {
    // o "pt" do JustWatch às vezes é de Portugal ("Raptadas"): o nome pt-BR do TMDB casa ("Os Suspeitos")
    $idx = Indice::deLista([[1, 'm', 'Os Suspeitos', 2013, null], [2, 'm', 'Homem-Aranha: Sem Volta Para Casa', 2021, null]]);
    $a = Top10::casar($idx, 'm', ['tmdb' => 146233, 'nome' => 'Raptadas', 'nome_original' => 'Prisoners', 'ano' => 2013]);
    $b = Top10::casar($idx, 'm', ['tmdb' => 634649, 'nome' => 'Homem-Aranha: Sem Volta a Casa', 'nome_original' => 'Spider-Man: No Way Home', 'ano' => 2021]);
    return "Raptadas → título " . ($a ?? 'nenhum') . "; Sem Volta a Casa → título " . ($b ?? 'nenhum');
});
$passo('ESPN: placar', function () {
    $r = Http::json('https://site.api.espn.com/apis/site/v2/sports/soccer/all/scoreboard?limit=1000&dates=' . Jogos::diaEspn(time()));
    return count($r['events'] ?? []) . ' jogos hoje';
});
$passo('Stremio: legendas', function () {
    $r = Http::json('https://opensubtitles-v3.strem.io/subtitles/series/tt0903747:1:1.json');
    $l = Legendas::ordenar($r['subtitles'] ?? []);
    $d = Http::get($l[0]['url'] ?? '');
    $txt = Legendas::paraUtf8($d['corpo']);
    return sprintf('%d em português; 1ª: %s; arquivo %d bytes, SRT %s, UTF-8 %s', count($l), $l[0]['nome'] ?? '-', strlen($txt),
        str_contains($txt, '-->') ? 'sim' : 'não', mb_check_encoding($txt, 'UTF-8') ? 'sim' : 'não');
});

if (!in_array('--catalogo', $argv, true)) {
    exit(0);
}

// ---- catálogo: uma vez, uma lista de cada vez, só estatísticas
$linhas = [];
foreach (['m' => 'get_vod_streams', 's' => 'get_series'] as $tipo => $acao) {
    $ini = $t();
    $lista = Xtream::api($conta, $acao, [], 240);
    $baixou = $t() - $ini;
    $chaves = array_keys($lista[0] ?? []);
    $comTmdb = count(array_filter($lista, fn($x) => (int) ($x['tmdb'] ?? $x['tmdb_id'] ?? 0) > 0));
    $v = Catalogo::lerVersoes($tipo, $lista);
    unset($lista);
    $ini2 = $t();
    $g = Catalogo::agrupar($v);
    $leg = count(array_filter($v, fn($x) => $x['leg']));
    $uhd = count(array_filter($v, fn($x) => $x['uhd']));
    $multi = count(array_filter($g, fn($x) => count($x['membros']) > 1));
    $comPar = count(array_filter($g, function ($x) use ($v) {
        $l = array_map(fn($m) => $v[$m]['leg'], $x['membros']);
        return in_array(true, $l, true) && in_array(false, $l, true);
    }));
    $legSozinha = count(array_filter($g, fn($x) => count($x['membros']) === 1 && $v[$x['membros'][0]]['leg']));
    printf("%s: %d entradas em %.1f s (campos: %s)\n", $tipo === 'm' ? 'filmes' : 'séries', count($v), $baixou, implode(',', $chaves));
    printf("   tmdb do painel: %d · [L]: %d · 4K: %d → %d títulos (%.2f s); %d com mais de uma versão; %d com dublada+legendada; %d só legendada\n",
        $comTmdb, $leg, $uhd, count($g), $t() - $ini2, $multi, $comPar, $legSozinha);
    $maior = 0;
    foreach ($g as $x) {
        $maior = max($maior, count($x['membros']));
    }
    printf("   maior grupo: %d versões · memória de pico %.0f MB\n", $maior, memory_get_peak_usage(true) / 1048576);
    foreach ($g as $i => $x) {
        $p = $v[$x['principal']];
        $linhas[] = [count($linhas) + 1, $tipo, Texto::limparNome($p['n']), $x['ano'], $x['tmdb']];
    }
    unset($v, $g);
    gc_collect_cycles();
}
$idx = Indice::deLista($linhas);
unset($linhas);
if ($top) {
    $casados = 0;
    $total = 0;
    $fora = [];
    foreach ($top as $codigo => $porTipo) {
        foreach ($porTipo as $tipo => $itens) {
            foreach ($itens as $e) {
                $total++;
                if (Top10::casar($idx, $tipo, $e)) {
                    $casados++;
                } else {
                    $fora[] = "$codigo/$tipo {$e['nome']}";
                }
            }
        }
    }
    printf("Top 10 de hoje × lista: %d de %d casados (os outros podem não estar na lista: %s)\n", $casados, $total, implode('; ', array_slice($fora, 0, 12)));
}
