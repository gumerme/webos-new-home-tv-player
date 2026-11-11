<?php
declare(strict_types=1);

// Recomendador (porte do recommend() da v10) e TMDB enxuto/busca, com histórico de exemplo.
require_once __DIR__ . '/_base.php';

use Casa\Play\Dados\Recomendador;
use Casa\Play\Dados\Tmdb;

$agora = mktime(20, 0, 0, 11, 8, 2026);
$dia = 86400;

// ---- peso dos sinais
$terminou = Recomendador::pesoSinal(['t' => $agora, 'f' => 0.95, 'eps' => 0, 'tipo' => 'm'], $agora);
$metade = Recomendador::pesoSinal(['t' => $agora, 'f' => 0.5, 'eps' => 0, 'tipo' => 'm'], $agora);
$largou = Recomendador::pesoSinal(['t' => $agora, 'f' => 0.02, 'eps' => 0, 'tipo' => 'm'], $agora);
$serie = Recomendador::pesoSinal(['t' => $agora, 'f' => 0.3, 'eps' => 6, 'tipo' => 's'], $agora);
$antigo = Recomendador::pesoSinal(['t' => $agora - 14 * $dia, 'f' => 0.95, 'eps' => 0, 'tipo' => 'm'], $agora);
igual(1.15, round($terminou, 3), 'terminou = 1,15');
verdade($terminou > $metade && $metade > 0, 'terminou > metade');
igual(-0.7, round($largou, 3), 'largou no começo conta contra');
igual(1.6, round($serie, 3), 'série com 6 episódios = like forte (teto 1,6)');
igual(0.575, round($antigo, 3), 'meia-vida de 14 dias');
igual(1.3, Recomendador::recencia(2026, 2026), 'recência do ano');
igual(1.0, Recomendador::recencia(2010, 2026), 'recência some em 8 anos');
igual(0.92, Recomendador::recencia(1990, 2026), 'antigo pesa um pouco menos');
verdade(Recomendador::naEpoca(Recomendador::EPOCA_PADRAO, $agora), 'novembro é Natal');
verdade(Recomendador::naEpoca(Recomendador::EPOCA_PADRAO, mktime(12, 0, 0, 1, 3, 2027)), '3 de janeiro também');
verdade(!Recomendador::naEpoca(Recomendador::EPOCA_PADRAO, mktime(12, 0, 0, 3, 1, 2027)), 'março não');

// ---- sinais: histórico + notas + semente + lista
$hist = [
    ['titulo_id' => 1, 'tipo' => 's', 'nome' => 'Breaking Bad', 't' => $agora - 2 * $dia, 'f' => 0.9, 'eps' => 12],
    ['titulo_id' => 2, 'tipo' => 'm', 'nome' => 'Filme Chato', 't' => $agora - 1 * $dia, 'f' => 0.03, 'eps' => 0],
    ['titulo_id' => 3, 'tipo' => 'm', 'nome' => 'Interestelar', 't' => $agora - 5 * $dia, 'f' => 1.0, 'eps' => 0],
];
$notas = [2 => -1, 3 => 1, 9 => 1];
$s = Recomendador::sinais($hist, $notas, [7, 1], [8 => 1.0], [1 => true, 2 => true, 3 => true, 9 => true, 8 => true], $agora);
$porId = array_column($s, null, 'id');
igual(-1.2, $porId[2]['w'], '"Não gostei" vira -1,2');
verdade($porId[3]['w'] >= 1.12, '"Gostei" reforça (≥ 0,8 × 1,4)');
igual(1.1, $porId[9]['w'], 'avaliado sem histórico = 1,1');
igual(0.9, $porId[8]['w'], 'semente do gosto = 0,9');
igual(0.6, $porId[7]['w'], 'Minha lista = 0,6');
verdade(!isset($porId[1]['lista']), 'na lista mas assistido não vira sinal de lista');
igual(1, $s[0]['id'], 'o mais forte primeiro (série maratonada)');

// ---- perfil de gosto
$sinais = [
    ['id' => 1, 'tipo' => 's', 'w' => 1.5, 'f' => ['generos' => [18, 80], 'keywords' => [100], 'pessoas' => [17419], 'decada' => 200, 'colecao' => 0]],
    ['id' => 3, 'tipo' => 'm', 'w' => 1.2, 'f' => ['generos' => [878, 18], 'keywords' => [200], 'pessoas' => [525], 'decada' => 201, 'colecao' => 0]],
    ['id' => 2, 'tipo' => 'm', 'w' => -1.2, 'f' => ['generos' => [27], 'keywords' => [300], 'pessoas' => [1], 'decada' => 202, 'colecao' => 0]],
];
$gosto = ['generos' => ['35' => 1, '28' => 0.9], 'pessoas' => [['tmdb' => 1086110, 'w' => 1]]];
$p = Recomendador::montarPerfil($sinais, $gosto, Recomendador::EPOCA_PADRAO);
igual(1.0, $p['generos'][18], 'drama no topo (normalizado)');
verdade($p['generos'][27] < 0, 'terror negativo (não gostou)');
verdade($p['generos'][35] > 0.9 && $p['generos'][28] > 0.8, 'semente: comédia e ação');
igual(1.0, $p['pessoas'][1086110], 'Leandro Hassum (semente ×3) no topo das pessoas');
verdade(isset($p['keywords'][207317]), 'época: palavra-chave do Natal');
igual(18, $p['top_generos'][0], 'top gênero = drama');
verdade(!in_array(27, $p['top_generos'], true), 'terror fora do top');

// ---- pontuação
$sets = [
    ['sinal' => 1, 'w' => 1.5, 'tipo' => 's', 'lista' => [
        ['id' => 60059, 'name' => 'Better Call Saul', 'first_air_date' => '2015-02-08', 'genre_ids' => [80, 18], 'vote_average' => 8.7, 'vote_count' => 5000],
        ['id' => 63351, 'name' => 'Narcos', 'first_air_date' => '2015-08-28', 'genre_ids' => [80, 18], 'vote_average' => 8.1, 'vote_count' => 3000],
        ['id' => 999, 'name' => 'Terror Ruim', 'first_air_date' => '2024-01-01', 'genre_ids' => [27], 'vote_average' => 4.0, 'vote_count' => 30],
    ]],
    ['sinal' => 3, 'w' => 1.2, 'tipo' => 'm', 'lista' => [
        ['id' => 27205, 'title' => 'A Origem', 'release_date' => '2010-07-15', 'genre_ids' => [28, 878], 'vote_average' => 8.4, 'vote_count' => 37000],
    ]],
    ['sinal' => null, 'w' => 0.2, 'tipo' => null, 'lista' => [
        ['id' => 60059, 'media_type' => 'tv', 'name' => 'Better Call Saul', 'first_air_date' => '2015-02-08', 'genre_ids' => [80, 18], 'vote_average' => 8.7, 'vote_count' => 5000],
    ]],
];
$c = Recomendador::pontuar($sets, $p, true, ['tv60059' => 0], 2026);
igual('tv', isset($c[0]['r']['name']) ? 'tv' : 'movie', 'série primeiro');
igual(60059, $c[0]['r']['id'], 'Better Call Saul em 1º (2 fontes + gosto)');
igual(2, $c[0]['fontes'], 'corroborado por 2 fontes');
verdade(isset($c[0]['porque'][1]), '"porque assistiu" Breaking Bad');
$ids = array_map(fn($x) => $x['r']['id'], $c);
verdade(array_search(999, $ids, true) === count($ids) - 1, 'terror ruim por último');

// ---- variedade e fileiras
$esc = [];
foreach ($c as $i => $x) {
    $esc[] = $x + ['id' => 100 + $i, 'genero' => $x['r']['genre_ids'][0] ?? 0, 'colecao' => 0];
}
$f = Recomendador::finalizar($esc, $p, $sinais, [18 => 'Drama', 80 => 'Crime', 878 => 'Ficção científica']);
igual(count($esc), count($f['recomendados']), 'todos entram (menos de 40)');
igual(100, $f['recomendados'][0], 'melhor primeiro');
igual([], $f['porque'], 'porque: precisa de ≥ 4 títulos');
// muitos títulos do mesmo gênero: o 2º do gênero perde para um de outro gênero com nota parecida
$muitos = [];
for ($i = 0; $i < 10; $i++) {
    $muitos[] = ['id' => 200 + $i, 'tipo' => 'm', 'r' => ['genre_ids' => [$i < 6 ? 18 : 35], 'vote_average' => 7.5], 'nota' => 10 - $i * 0.1,
                 'porque' => [1 => 1.0], 'genero' => $i < 6 ? 18 : 35, 'colecao' => 0];
}
$f = Recomendador::finalizar($muitos, $p, $sinais, [18 => 'Drama', 35 => 'Comédia']);
igual(200, $f['recomendados'][0], '1º drama');
igual(206, $f['recomendados'][1], 'variedade: depois uma comédia');
igual([['titulo_id' => 1, 'nome' => '', 'ids' => [200, 201, 202, 203, 204, 205, 206, 207, 208, 209]]], $f['porque'], 'porque assistiu Breaking Bad');
igual('Drama', $f['generos'][0]['nome'], 'fileira de gênero: Drama');
// "Gostei" sem histórico (avaliado) também vira "Porque você assistiu" (gostei = já vi, 09/11 noite)
$sAval = [['id' => 9, 'tipo' => 'm', 'w' => 1.1, 'avaliado' => true, 'nome' => 'Gostado', 'f' => ['generos' => [18], 'keywords' => [], 'pessoas' => [], 'decada' => 201, 'colecao' => 0]],
          ['id' => 11, 'tipo' => 'm', 'w' => -1.0, 'avaliado' => true, 'nome' => 'Odiado', 'f' => ['generos' => [27], 'keywords' => [], 'pessoas' => [], 'decada' => 201, 'colecao' => 0]]];
$mAval = array_map(fn($x) => ['porque' => [9 => 1.0, 11 => 1.0]] + $x, $muitos);
$f = Recomendador::finalizar($mAval, $p, $sAval, [18 => 'Drama', 35 => 'Comédia']);
igual([9], array_column($f['porque'], 'titulo_id'), '"porque": Gostei sem histórico entra; Não gostei não');

// ---- TMDB: escolha da busca e enxuto
$r = [['id' => 1, 'title' => 'Halloween', 'release_date' => '2018-10-18'], ['id' => 2, 'title' => 'Halloween', 'release_date' => '1978-10-25']];
igual(2, Tmdb::escolherBusca($r, 'Halloween', 1978, 'm'), 'busca: mesmo nome e ano');
igual(null, Tmdb::escolherBusca($r, 'Halloween', 2000, 'm'), 'filme de outro ano não serve');
igual(5, Tmdb::escolherBusca([['id' => 5, 'name' => 'Meu Nome é Farah', 'first_air_date' => '2023-03-08']], 'Meu Nome é Farah', 2025, 's'), 'série: ano do painel pode ser outro');
$e = Tmdb::enxuto([
    'id' => 157336, 'title' => 'Interestelar', 'original_title' => 'Interstellar', 'release_date' => '2014-11-05', 'runtime' => 169,
    'imdb_id' => 'tt0816692', 'poster_path' => '/p.jpg', 'backdrop_path' => '/b.jpg', 'overview' => 'Viagem.', 'genres' => [['id' => 12, 'name' => 'Aventura'], ['id' => 878, 'name' => 'Ficção científica']],
    'belongs_to_collection' => null, 'vote_average' => 8.46, 'vote_count' => 38000,
    'credits' => ['cast' => [['id' => 10297, 'name' => 'Matthew McConaughey', 'character' => 'Cooper', 'profile_path' => '/m.jpg']],
                  'crew' => [['id' => 525, 'name' => 'Christopher Nolan', 'job' => 'Director'], ['id' => 9, 'name' => 'X', 'job' => 'Editor']]],
    'keywords' => ['keywords' => [['id' => 1612, 'name' => 'spacecraft']]],
    'videos' => ['results' => [['site' => 'YouTube', 'key' => 'clip', 'type' => 'Clip', 'iso_639_1' => 'en'],
                               ['site' => 'YouTube', 'key' => 'en1', 'type' => 'Trailer', 'iso_639_1' => 'en', 'official' => true],
                               ['site' => 'YouTube', 'key' => 'pt1', 'type' => 'Trailer', 'iso_639_1' => 'pt']]],
    'release_dates' => ['results' => [['iso_3166_1' => 'BR', 'release_dates' => [['certification' => '', 'type' => 2], ['certification' => '10', 'type' => 3]]]]],
], 'm');
igual('tt0816692', $e['imdb'], 'imdb');
igual([12, 878], $e['generos'], 'gêneros');
igual('pt1', $e['detalhes']['trailer'], 'trailer em português primeiro');
igual('10', $e['detalhes']['classificacao'], 'classificação BR');
igual(169, $e['detalhes']['duracao_min'], 'duração');
igual([['tmdb' => 525, 'nome' => 'Christopher Nolan']], $e['detalhes']['diretor'], 'diretor');
igual(2, count($e['pessoas']), 'pessoas: elenco + direção');
igual([1612], $e['detalhes']['keywords'], 'palavras-chave');

fim(__FILE__);
