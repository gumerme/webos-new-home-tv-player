<?php
declare(strict_types=1);

// Parse do Top 10 do JustWatch (resposta real guardada em dados/justwatch_top10.json, 08/11/2026).
require_once __DIR__ . '/_base.php';

use Casa\Play\Dados\Indice;
use Casa\Play\Dados\JustWatch;
use Casa\Play\Dados\Top10;

$q = JustWatch::consultaTop10(['nfx' => 'nfx', 'gop' => 'gop']);
verdade(str_contains($q, 'p_nfx_m:popularTitles(country:BR,first:10,sortBy:POPULAR,filter:{packages:["nfx"],objectTypes:[MOVIE]})'), 'consulta filmes nfx');
verdade(str_contains($q, 'p_gop_s:popularTitles(') && str_contains($q, 'objectTypes:[SHOW]'), 'consulta séries gop');

$l = JustWatch::lerTop10(dados('justwatch_top10.json'), ['nfx', 'gop']);
igual(4, count($l['nfx']['m']), '4 filmes nfx');
igual(4, count($l['gop']['s']), '4 séries gop');
$p = $l['nfx']['m'][0];
igual(1, $p['posicao'], 'posição 1');
igual('m', $p['tipo'], 'tipo filme');
verdade(is_int($p['tmdb']) && $p['tmdb'] > 0, 'tmdb inteiro');
verdade(str_starts_with((string) $p['poster'], 'https://images.justwatch.com/poster/') && str_contains($p['poster'], '/s332/'), 'pôster s332');
verdade(!str_contains((string) $p['poster'], '{'), 'pôster sem modelo');
verdade(isset($p['notas']['imdb']), 'nota IMDb');
igual('s', $l['gop']['s'][0]['tipo'], 'série é s');
verdade(str_starts_with((string) $l['gop']['s'][0]['jw_id'], 'ts'), 'jw_id de série');

// notas e qualidade (jwQuality da v10)
$n = JustWatch::notas(['id' => 'tm1', 'content' => ['scoring' => ['imdbScore' => 8.2, 'imdbVotes' => 967558, 'tomatoMeter' => 82],
                                                   'interactions' => ['likelistAdditions' => 13849, 'dislikelistAdditions' => 426]]]);
igual(['g' => [], 'id' => 'tm1', 'imdb' => 8.2, 'imdb_votos' => 967558, 'rotten' => 82, 'gostaram' => 97, 'curtidas' => 13849, 'descurtidas' => 426], $n, 'notas');
$alta = JustWatch::qualidade($n, true);
$baixa = JustWatch::qualidade(['imdb' => 5.1, 'imdb_votos' => 17125, 'rotten' => 14, 'curtidas' => 194, 'descurtidas' => 92], true);
verdade($alta > $baixa && $alta > 1.0, 'qualidade: Raptadas > Return to Sender');
igual(0.0, JustWatch::qualidade(null, true), 'sem JustWatch = 0');
igual(null, JustWatch::notas(['content' => ['interactions' => ['likelistAdditions' => 4, 'dislikelistAdditions' => 1]]])['gostaram'] ?? null, 'poucos votos: sem %');

// casamento com a lista (sem rede: tmdb conhecido e nome+ano)
$primeiro = $l['nfx']['m'][0];
$idx = Indice::deLista([[10, 'm', $primeiro['nome'], $primeiro['ano'], null], [11, 'm', 'Outro', 2020, $l['nfx']['m'][1]['tmdb']]]);
igual(10, Top10::casar($idx, 'm', $primeiro), 'casa pelo nome+ano');
igual(11, Top10::casar($idx, 'm', $l['nfx']['m'][1]), 'casa pelo tmdb');

// escolha do resultado da busca por título (jwPick)
$edges = [['node' => ['content' => ['title' => 'Duna', 'originalReleaseYear' => 1984, 'externalIds' => ['tmdbId' => '841']]]],
          ['node' => ['content' => ['title' => 'Duna', 'originalReleaseYear' => 2021, 'externalIds' => ['tmdbId' => '438631']]]]];
igual('438631', JustWatch::escolher(['tmdb' => 438631, 'nome' => 'Duna'], $edges)['content']['externalIds']['tmdbId'] ?? null, 'escolher pelo tmdb');
igual(2021, JustWatch::escolher(['tmdb' => null, 'nome' => 'Duna', 'ano' => 2021], $edges)['content']['originalReleaseYear'] ?? null, 'escolher pelo nome+ano');

fim(__FILE__);
