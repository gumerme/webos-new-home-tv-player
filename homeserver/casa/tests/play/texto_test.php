<?php
declare(strict_types=1);

// Normalização (igual à v10) e junção das versões dublada / legendada / 4K em títulos.
require_once __DIR__ . '/_base.php';

use Casa\Play\Dados\Catalogo;
use Casa\Play\Dados\Indice;
use Casa\Play\Dados\Texto;

// ---- norm / limparNome / marcas
igual('interestelar', Texto::norm('Interestelar'), 'norm simples');
igual('interestelar', Texto::norm('Interestelar [L]'), 'norm tira [L]');
igual('duna parte dois', Texto::norm('Duna: Parte Dois 4K [DV][HDR]'), 'norm tira 4K e colchetes');
igual('o poderoso chefao', Texto::norm('O Poderoso Chefão (1972)'), 'norm tira acento e ano');
igual('a mae', Texto::norm('A Mãe - Dublado'), 'norm tira "dublado"');
igual('oneill', Texto::norm("O'Neill"), 'norm tira apóstrofo');
igual('pokemon o filme', Texto::norm('Pokémon: O Filme'), 'norm é');
igual('acao', Texto::fold('AÇÃO'), 'fold maiúsculas');
igual('Interestelar', Texto::limparNome('Interestelar [L]'), 'limparNome [L]');
igual('Duna: Parte Dois', Texto::limparNome('Duna: Parte Dois 4K [DV][HDR]'), 'limparNome 4K');
igual('Oppenheimer', Texto::limparNome('Oppenheimer (2023)'), 'limparNome ano');
verdade(Texto::ehLegendado('Pecadores [L]'), 'ehLegendado');
verdade(!Texto::ehLegendado('Pecadores'), 'não legendado');
verdade(Texto::ehUhd('Pecadores 4K [HDR]'), 'ehUhd 4K');
verdade(Texto::ehUhd('Pecadores [DV]'), 'ehUhd DV');
verdade(!Texto::ehUhd('Os 4 Fantásticos'), 'não UHD');
igual(2014, Texto::ano('Interestelar (2014)'), 'ano do nome');
igual(2025, Texto::ano('Pecadores', '2025-04-17'), 'ano do campo');
igual(0, Texto::ano('Sem Ano', ''), 'sem ano');
igual('Ação', Texto::acentuar('Filmes | Acao'), 'acentuar Ação');
igual('Lançamentos', Texto::acentuar('Filmes | Lancamentos'), 'acentuar Lançamentos');
igual('Globoplay', Texto::acentuar('Series | Globoplay'), 'acentuar tira prefixo');
verdade(!Texto::ehGenero('Filmes | Lancamentos'), 'lançamentos não é gênero');
verdade(!Texto::ehGenero('Series | Legendadas'), 'legendadas não é gênero');
verdade(Texto::ehGenero('Filmes | Comedia Romantica'), 'comédia romântica é gênero');
verdade(Texto::latino('Interestelar') && !Texto::latino('Звёздные войны'), 'latino');

// ---- junção (nomes reais do painel)
$lista = [
    ['stream_id' => 63244, 'name' => 'Interestelar', 'year' => '2014', 'tmdb' => 157336, 'container_extension' => 'mp4', 'category_id' => '10'],
    ['stream_id' => 70001, 'name' => 'Interestelar [L]', 'year' => '2014', 'tmdb' => 157336, 'container_extension' => 'mp4', 'category_id' => '7'],
    ['stream_id' => 80001, 'name' => 'Interestelar 4K [DV][HDR]', 'year' => '2014', 'container_extension' => 'mkv', 'category_id' => '3'],
    ['stream_id' => 50000, 'name' => 'Halloween (1978)', 'container_extension' => 'mp4', 'category_id' => '20'],
    ['stream_id' => 90000, 'name' => 'Halloween (2018)', 'container_extension' => 'mp4', 'category_id' => '20'],
    ['stream_id' => 90001, 'name' => 'Halloween [L]', 'year' => '2018', 'container_extension' => 'mp4', 'category_id' => '7'],
    ['stream_id' => 91000, 'name' => 'Pecadores', 'year' => '2025', 'tmdb' => 1233413],
    ['stream_id' => 91001, 'name' => 'Pecadores', 'year' => '2025', 'tmdb' => 1233413],      // o painel lista duas vezes
    ['stream_id' => 92000, 'name' => 'Duna', 'year' => '2021', 'tmdb' => 438631],
    ['stream_id' => 92001, 'name' => 'Duna', 'year' => '1984', 'tmdb' => 841],
    ['stream_id' => 93000, 'name' => 'Sem Ano Nenhum'],
    ['stream_id' => 93001, 'name' => 'Sem Ano Nenhum [L]'],
    ['stream_id' => 94000, 'name' => 'Mesmo Ano Tmdb Diferente', 'year' => '2020', 'tmdb' => 1],
    ['stream_id' => 94001, 'name' => 'Mesmo Ano Tmdb Diferente', 'year' => '2020', 'tmdb' => 2],
];
$v = Catalogo::lerVersoes('m', $lista);
igual(14, count($v), 'lerVersoes lê todas');
igual(true, $v[1]['leg'], 'versão [L] é leg');
igual(true, $v[2]['uhd'], 'versão 4K é uhd');
igual(1978, $v[3]['y'], 'ano pelo nome');
$g = Catalogo::agrupar($v);
$porPainel = [];
foreach ($g as $i => $grupo) {
    foreach ($grupo['membros'] as $m) {
        $porPainel[$v[$m]['p']] = $i;
    }
}
$mesmo = fn($a, $b) => $porPainel[$a] === $porPainel[$b];
verdade($mesmo(63244, 70001) && $mesmo(63244, 80001), 'Interestelar: dublado, [L] e 4K são um título');
verdade(!$mesmo(50000, 90000), 'Halloween 1978 ≠ 2018');
verdade($mesmo(90000, 90001), 'Halloween 2018 e [L] juntos');
verdade($mesmo(91000, 91001), 'Pecadores listado duas vezes = um título');
verdade(!$mesmo(92000, 92001), 'Duna 2021 ≠ Duna 1984 (tmdb e ano)');
verdade($mesmo(93000, 93001), 'sem ano: [L] junta com o dublado');
verdade(!$mesmo(94000, 94001), 'mesmo nome/ano mas tmdb diferente: separados');
igual(9, count($g), '9 títulos');
$gi = $porPainel[63244];
igual(63244, $v[$g[$gi]['principal']]['p'], 'principal = dublado sem 4K');
igual(157336, $g[$gi]['tmdb'], 'tmdb do grupo');
$gh = $porPainel[90001];
igual(90000, $v[$g[$gh]['principal']]['p'], 'principal do Halloween 2018 é o dublado');

// ---- índice (findInList da v10)
$idx = Indice::deLista([
    [1, 'm', 'Interestelar', 2014, 157336],
    [2, 'm', 'Halloween', 1978, null],
    [3, 'm', 'Halloween', 2018, null],
    [4, 'm', 'Missão: Impossível - Acerto de Contas Parte Um', 2023, null],
    [5, 's', 'From - A Origem do Mal', 2022, null],
    [6, 'm', 'Velozes e Furiosos', 2001, null],
]);
igual(1, $idx->achar('m', 'Interestelar', 2014), 'achar exato');
igual(1, $idx->achar('m', 'Interestelar', 2015), 'achar ano ±1');
igual(null, $idx->achar('m', 'Interestelar', 2017), 'ano longe não acha');
igual(3, $idx->achar('m', 'Halloween', 2018), 'homônimo pelo ano');
igual(4, $idx->achar('m', 'Missão: Impossível - Acerto de Contas', 2023), 'prefixo: lista começa com o nome');
igual(null, $idx->achar('s', 'From', 2022), 'nome curto (< 6 letras) não usa prefixo');
igual(5, $idx->achar('s', 'From - A Origem do Mal', 2022), 'série exata');
igual(6, $idx->achar('m', 'Velozes e Furiosos 10', 2001), 'o nome começa com um da lista');
igual(1, $idx->porTmdb('m', 157336), 'por tmdb');
igual(1, $idx->casarTmdb(['id' => 157336, 'title' => 'Interstellar'], 'm'), 'casarTmdb pelo id');
igual(null, $idx->casarTmdb(['id' => 999, 'title' => 'Interestelar', 'release_date' => '2014-11-06'], 'm'), 'casarTmdb: outro tmdb não casa');

fim(__FILE__);
