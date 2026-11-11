<?php
declare(strict_types=1);

// Montagem do Cartao, URLs de imagem, créditos e Canal.
require_once __DIR__ . '/api_util.php';

use Casa\Play\Api\AoVivo;
use Casa\Play\Api\Base;
use Casa\Play\Api\Cartoes;

$linha = [
    'id' => '123', 'tipo' => 'm', 'nome' => 'Interestelar', 'ano' => '2014', 'tmdb_id' => '157336',
    'poster' => '/gEU2QniE6E77NI6lCU6MxlNBvIx.jpg', 'fundo' => '/xJHokMbljvjADYdit5fK5VQsXEG.jpg', 'qualidade' => '4k',
    'imdb' => '8.7', 'ativo' => true, 'p_pos' => '4500', 'p_dur' => '10140', 'p_temp' => null, 'p_ep' => null,
    'p_term' => false, 'p_quando' => '2026-11-08 22:10:00+00', 'p_tv' => 'TV 2', 'p_versao' => '991',
    'minha_lista' => true, 'xt_id' => '50123', 'xt_ext' => 'mkv',
];
$c = Cartoes::montar($linha, [['codigo' => 'prv', 'posicao' => 3]]);
igual(123, $c['id'], 'id inteiro');
igual('m', $c['tipo'], 'tipo');
igual(2014, $c['ano'], 'ano inteiro');
igual('/api/play/img/w342/gEU2QniE6E77NI6lCU6MxlNBvIx.jpg', $c['poster'], 'pôster TMDB em w342');
igual('/api/play/img/w780/xJHokMbljvjADYdit5fK5VQsXEG.jpg', $c['fundo'], 'fundo TMDB em w780');
igual(8.7, $c['imdb'], 'nota IMDb como número');
igual('4k', $c['qualidade'], 'qualidade');
igual(true, $c['na_lista'], 'na lista');
igual([['codigo' => 'prv', 'posicao' => 3]], $c['plataformas'], 'plataformas do Top 10');
igual(['pct' => 44, 'restante_s' => 5640, 'temporada' => null, 'episodio' => null, 'tv' => 'TV 2',
       'quando' => '2026-11-08T19:10:00-03:00', 'proximo' => false], $c['progresso'], 'progresso do filme');
igual(null, $c['selo'], 'sem selo por padrão');
igual(['id', 'tmdb', 'tipo', 'nome', 'ano', 'poster', 'fundo', 'imdb', 'qualidade', 'na_lista', 'plataformas', 'progresso', 'selo', 'minha_lista', 'xt'],
    array_keys($c), 'campos do contrato (+ tmdb, minha_lista e xt pedidos pelo app), nessa ordem');
igual(157336, $c['tmdb'], 'tmdb em todo cartão');
igual(true, $c['minha_lista'], 'minha_lista em todo cartão');
igual(['id' => 50123, 'ext' => 'mkv'], $c['xt'], 'xt do filme: stream do painel para tocar sem o homeserver');
$semXt = Cartoes::montar(['xt_id' => null, 'minha_lista' => null] + $linha);
ok(!array_key_exists('xt', $semXt), 'sem versão = sem xt');
igual(false, $semXt['minha_lista'], 'minha_lista falsa');
igual('mp4', Cartoes::montar(['xt_ext' => null] + $linha)['xt']['ext'], 'xt sem extensão = mp4');

// Progresso: regras da v10.
igual(null, Cartoes::progresso(['p_pos' => 20, 'p_dur' => 6000, 'p_term' => false, 'p_quando' => '2026-11-08 10:00:00-03', 'tipo' => 'm']), 'menos de 30 s não conta');
igual(null, Cartoes::progresso(['p_pos' => 6000, 'p_dur' => 6000, 'p_term' => true, 'p_quando' => '2026-11-08 10:00:00-03', 'tipo' => 'm']), 'filme terminado sai do Continuar');
$s = Cartoes::progresso(['p_pos' => 2500, 'p_dur' => 2600, 'p_term' => 't', 'p_temp' => 3, 'p_ep' => 3, 'p_tv' => null,
                         'p_quando' => '2026-11-08 10:00:00-03', 'tipo' => 's']);
igual(true, $s['proximo'], 'série com episódio terminado = próximo');
igual(3, $s['temporada'], 'temporada do último episódio');
igual(null, Cartoes::progresso(['p_quando' => null]), 'sem progresso');
igual(99, Cartoes::progresso(['p_pos' => 5999, 'p_dur' => 6000, 'p_term' => false, 'p_quando' => '2026-11-08 10:00:00-03', 'tipo' => 'm'])['pct'], 'pct não chega a 100 sem terminar');

// Qualidade desconhecida vira null.
igual(null, Cartoes::montar(['qualidade' => 'xpto'] + $linha)['qualidade'], 'qualidade fora da lista = null');

// Fora da lista.
$f = Cartoes::fora(['tmdb' => '603', 'tipo' => 's', 'nome' => 'Matrix', 'ano' => 1999, 'poster' => '/abc.jpg']);
igual(null, $f['id'], 'fora: id null');
igual(603, $f['tmdb'], 'fora: tmdb');
igual(false, $f['na_lista'], 'fora: na_lista false');
igual('/api/play/img/w342/abc.jpg', $f['poster'], 'fora: pôster');
igual(false, $f['minha_lista'], 'fora: minha_lista falsa');
ok(!array_key_exists('xt', $f), 'fora: sem xt');

// Imagens.
igual(null, Base::img(null), 'img nula');
igual(null, Base::img('/../etc/passwd'), 'caminho com travessia não vira URL');
igual('/api/play/img/w185/x.png', Base::img('https://image.tmdb.org/t/p/w500/x.png', 'w185'), 'URL TMDB completa vira caminho local');
$u = 'http://painel.exemplo:8080/images/logo canal.png';
igual(null, Base::img($u), 'URL com espaço é recusada');
$u = 'http://painel.exemplo:8080/images/logo.png';
$img = Base::img($u);
ok(str_starts_with($img, '/api/play/img/painel/' . Base::assinar($u) . '?u='), 'imagem do painel assinada');
igual('https://image.tmdb.org/t/p/w342/a.jpg', Base::imgAbsoluta('/a.jpg'), 'URL absoluta para integrações');

// Créditos (v10: filme 5% entre 3 e 8 min; episódio 4% entre 1 e 2,5 min).
igual(180, Base::creditos('m', 1800), 'filme curto: 3 min');
igual(360, Base::creditos('m', 7200), 'filme de 2 h: 6 min');
igual(480, Base::creditos('m', 14400), 'filme longo: 8 min');
igual(60, Base::creditos('s', 1200), 'episódio de 20 min: 1 min');
igual(150, Base::creditos('s', 6000), 'episódio longo: 2,5 min');
igual(300, Base::creditos('m', 0), 'sem duração: padrão');

// Arrays do Postgres.
igual('{1,2,3}', Base::arr([1, '2', 3, 3, null, 'x']), 'array para o Postgres sem repetidos');
igual([28, 12], Base::lerArr('{28,12}'), 'lê array do Postgres');
igual([], Base::lerArr('{}'), 'array vazio');
igual(['Ação', 'Aventura'], Base::generos([28, 12, 99999]), 'nomes dos gêneros');
igual('2026-11-08T19:10:00-03:00', Base::iso('2026-11-08 22:10:00+00'), 'ISO no fuso da casa');
igual('2026-11-08T00:00:00-03:00', Base::hojeInicio(new DateTimeImmutable('2026-11-08 23:59:00-03:00')), 'começo do dia');

// Canal.
$canal = AoVivo::canal(['painel_id' => '12', 'nome' => 'ESPN FHD', 'numero' => '30', 'logo' => null, 'grupo' => 'Esportes',
    'favorito' => true, 'agora_titulo' => 'SportsCenter', 'agora_inicio' => '2026-11-08 19:00:00-03', 'agora_fim' => '2026-11-08 20:00:00-03'],
    strtotime('2026-11-08 19:30:00-03'));
igual(['id' => 12, 'nome' => 'ESPN FHD', 'numero' => 30, 'logo' => null, 'grupo' => 'Esportes', 'favorito' => true,
       'agora' => ['titulo' => 'SportsCenter', 'inicio' => '2026-11-08T19:00:00-03:00', 'fim' => '2026-11-08T20:00:00-03:00', 'pct' => 50],
       'qualidade' => 'fhd', 'xt' => ['id' => 12]], $canal, 'Canal no formato do contrato (+ xt)');
igual('4k', Base::qualidadeCanal('Globo 4K'), 'qualidade do canal 4K');
igual('hd', Base::qualidadeCanal('SBT HD'), 'qualidade do canal HD');
igual(null, Base::qualidadeCanal('Band'), 'sem qualidade no nome');

fim('api_cartao');
