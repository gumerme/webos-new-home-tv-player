<?php
declare(strict_types=1);

// Filtragem colaborativa (MovieLens/Trakt): registros e conjuntos para o pontuar(). Puro.
require_once __DIR__ . '/_base.php';

use Casa\Play\Dados\Colaborativo;
use Casa\Play\Dados\Recomendador;
use Casa\Play\Dados\Trakt;

$r = Colaborativo::registro('m', ['tmdb_id' => 603, 'nome' => 'Matrix', 'ano' => 1999, 'generos' => '{28,878}', 'imdb' => '8.7', 'votos' => '2100000', 'nota_painel' => null]);
igual(['id' => 603, 'media_type' => 'movie', 'genre_ids' => [28, 878], 'vote_average' => 8.7, 'vote_count' => 2100000, '_cf' => 1,
       'title' => 'Matrix', 'release_date' => '1999-01-01'], $r, 'registro TMDB do filme do catálogo');
$s = Colaborativo::registro('s', ['tmdb_id' => 1396, 'nome' => 'Breaking Bad', 'ano' => null, 'generos' => '{}', 'imdb' => null, 'votos' => null, 'nota_painel' => '9.1']);
igual(9.1, $s['vote_average'], 'sem IMDb usa a nota do painel');
igual('', $s['first_air_date'], 'série sem ano');

$reg = ['m603' => $r, 'm604' => ['id' => 604, 'media_type' => 'movie', 'title' => 'Matrix Reloaded'], 's1396' => $s];
$viz = ['m27205' => ['ml' => [[603, 'm'], [999, 'm'], [604, 'm']], 'trakt' => [[1396, 's']]],
        's60059' => ['trakt' => [[1396, 's']]]];
$sinais = [['id' => 1, 'w' => 1.2, 'tipo' => 'm', 'tmdb' => 27205], ['id' => 2, 'w' => -1.0, 'tipo' => 's', 'tmdb' => 60059],
           ['id' => 3, 'w' => 0.8, 'tipo' => 's', 'tmdb' => 1]];
$sets = Colaborativo::conjuntos($sinais, $viz, $reg);
igual(2, count($sets), 'MovieLens + Trakt do sinal positivo; o negativo e o sem vizinhos não geram');
igual([603, 604], array_column($sets[0]['lista'], 'id'), 'vizinho fora do catálogo sai, ordem mantida');
igual(1.5, round($sets[0]['w'], 3), 'MovieLens pesa 1,25 × o sinal');
igual(null, $sets[0]['tipo'], 'mídia vem do registro (Trakt pode cruzar tipo)');
igual(0.48, round(Colaborativo::conjuntos($sinais, $viz, $reg, 0.5)[1]['w'], 3), 'fator (obras extras da Netflix)');

// no pontuar: o vizinho apontado por duas fontes passa à frente
$c = Recomendador::pontuar(array_merge($sets, [['sinal' => 1, 'w' => 1.2, 'tipo' => 'm', 'lista' => [$reg['m604'] + ['vote_average' => 7, 'vote_count' => 500]]]]),
    ['generos' => [], 'decadas' => []], false, [], 2026);
igual(604, $c[0]['r']['id'], 'corroborado pelo TMDB e pelo MovieLens');
verdade(isset($c[0]['porque'][1]), '"porque você assistiu" continua');

igual([[1396, 's'], [1399, 's']], Trakt::relacionados([['title' => 'Breaking Bad', 'ids' => ['trakt' => 1, 'tmdb' => 1396]],
    ['title' => 'Sem tmdb', 'ids' => ['trakt' => 2]], ['ids' => ['tmdb' => 1399]]], 's'), 'Trakt: relacionados com tmdb');
// AniList: ponte do Fribb e soma dos votos das temporadas
use Casa\Play\Dados\AniList;
[$porTmdb, $paraTmdb] = AniList::indexar([
    ['type' => 'TV', 'anilist_id' => 21, 'themoviedb_id' => ['tv' => 37854]],
    ['type' => 'TV', 'anilist_id' => 16498, 'themoviedb_id' => ['tv' => 1429]],
    ['type' => 'TV', 'anilist_id' => 20958, 'themoviedb_id' => ['tv' => 1429]],
    ['type' => 'MOVIE', 'anilist_id' => 199, 'themoviedb_id' => ['movie' => [129]]],
    ['type' => 'TV', 'anilist_id' => 11061, 'themoviedb_id' => ['tv' => 46298]],
    ['type' => 'OVA', 'anilist_id' => 5],
]);
igual([16498, 20958], $porTmdb['s1429'], 'temporadas do AniList juntam na série do TMDB');
igual('m129', $paraTmdb[199], 'filme');
igual(false, isset($paraTmdb[5]), 'sem TMDB fica de fora');
$v = AniList::agregar([16498, 20958], [16498 => [[20958, 900], [21, 300], [199, 100]], 20958 => [[11061, 500], [21, 250], [999, 50]]], $paraTmdb, 's1429');
igual([[37854, 's'], [46298, 's'], [129, 'm']], $v, 'soma dos votos; ela mesma e sem TMDB saem');
fim(__FILE__);
