<?php
declare(strict_types=1);

// Home das TVs: destaque em repouso, sinopse curta e clima. Puro.
require_once __DIR__ . '/_base.php';

use Casa\Play\Api\Launcher;
use Casa\Play\Dados\Clima;

$jogo = fn($s) => ['status' => $s];
igual(['fileira' => 'jogos', 'i' => 1], Launcher::destaque(['jogos' => [$jogo('depois'), $jogo('agora')], 'continuar' => [['id' => 1]], 'recomendados' => [['id' => 2]]]), 'jogo ao vivo vem primeiro');
igual(['fileira' => 'continuar', 'i' => 0], Launcher::destaque(['jogos' => [$jogo('depois')], 'continuar' => [['id' => 1]], 'recomendados' => [['id' => 2]]]), 'depois o Continuar');
igual(['fileira' => 'recomendados', 'i' => 0], Launcher::destaque(['jogos' => [], 'continuar' => [], 'recomendados' => [['id' => 2]]]), 'depois a recomendação');
igual(null, Launcher::destaque(['jogos' => [], 'continuar' => [], 'recomendados' => []]), 'nada: sem destaque');

igual('Curta.', Launcher::curta("  Curta. "), 'curta fica');
$longa = 'Jerry e seus três amigos têm o poder de fazer rir. Não só nas situações ridículas, mas também nas corriqueiras, '
       . 'e isso rende nove temporadas de episódios sobre nada em Nova York com muita gente estranha.';
igual('Jerry e seus três amigos têm o poder de fazer rir. Não só nas situações ridículas, mas também nas corriqueiras, e isso rende nove temporadas de episódios sobre nada em…',
      Launcher::curta($longa), 'corta na palavra com reticências');
igual(null, Launcher::curta(''), 'vazia = null');

igual(['Céu limpo', 'sol'], Clima::ceu(0), 'WMO 0 de dia');
igual(['Noite limpa', 'lua'], Clima::ceu(0, false), 'WMO 0 de noite');
igual(['Chuva', 'chuva'], Clima::ceu(81), 'pancada = chuva');
igual(['Tempestade', 'tempestade'], Clima::ceu(95), 'trovoada');
$c = Clima::montar(['current' => ['temperature_2m' => 23.6, 'weather_code' => 2, 'is_day' => 1],
                    'daily' => ['time' => ['2026-11-09'], 'weather_code' => [61], 'temperature_2m_max' => [27.4], 'temperature_2m_min' => [16.2], 'sunset' => ['2026-11-09T19:52']]]);
igual(24, $c['temp'], 'temperatura arredondada');
igual('19:52', $c['por_do_sol'], 'pôr do sol');
igual(['data' => '2026-11-09', 'max' => 27, 'min' => 16, 'ceu' => 'Chuva', 'icone' => 'chuva'], $c['dias'][0], 'previsão do dia');
// trailer: dublado pt-BR vence; sem pt, o oficial em inglês (a TV liga a legenda)
use Casa\Play\Dados\Tmdb;
$v = fn($k, $l, $pais, $tipo = 'Trailer', $of = true) => ['site' => 'YouTube', 'key' => $k, 'iso_639_1' => $l, 'iso_3166_1' => $pais, 'type' => $tipo, 'official' => $of];
igual(['key' => 'br', 'idioma' => 'pt'], Tmdb::escolherTrailer([$v('en', 'en', 'US'), $v('pt', 'pt', 'PT'), $v('br', 'pt', 'BR')]), 'pt-BR antes de pt-PT e do inglês');
igual(['key' => 'tz', 'idioma' => 'pt'], Tmdb::escolherTrailer([$v('en', 'en', 'US'), $v('tz', 'pt', 'BR', 'Teaser')]), 'teaser dublado antes de trailer em inglês');
igual(['key' => 'en', 'idioma' => 'en'], Tmdb::escolherTrailer([$v('en2', 'en', 'US', 'Trailer', false), $v('en', 'en', 'US')]), 'sem português: oficial em inglês');
igual(null, Tmdb::escolherTrailer([['site' => 'Vimeo', 'key' => 'x', 'type' => 'Trailer']]), 'só YouTube');
// trailer em pt pelo YouTube: dublado > oficial de canal brasileiro > legendado; nome e duração conferidos
use Casa\Play\Dados\Trailer;
$vid = fn($id, $t, $s, $c) => ['id' => $id, 'titulo' => $t, 'segundos' => $s, 'canal' => $c];
$lista = [$vid('of', 'A Lista Terminal: Lobo Negro | Trailer Oficial | Prime Video', 148, 'Prime Video Brasil'),
          $vid('t2', 'A LISTA TERMINAL Temporada 2 Trailer Dublado Oficial (2026)', 87, 'ONE Media Brasil'),
          $vid('db', 'A LISTA TERMINAL: LOBO NEGRO | Trailer (2025) Dublado', 169, 'Space Trailers'),
          $vid('lg', 'A Lista Terminal: Lobo Negro - Trailer Legendado (2025)', 83, 'Cinema Todo Dia')];
igual(['id' => 'db', 'origem' => 'dublado'], Trailer::escolher($lista, 'A Lista Terminal Lobo Negro'), 'dublado com todas as palavras do nome (a temporada 2 de outra série não serve)');
igual(['id' => 'of', 'origem' => 'oficial-br'], Trailer::escolher([$lista[0], $lista[3]], 'A Lista Terminal Lobo Negro'), 'sem dublado: oficial do canal brasileiro');
igual(['id' => 'lg', 'origem' => 'legendado'], Trailer::escolher([$lista[3]], 'A Lista Terminal Lobo Negro'), 'depois o legendado');
igual(null, Trailer::escolher([$vid('x', 'Lobo Negro trailer dublado', 9000, 'X')], 'A Lista Terminal Lobo Negro'), 'nome incompleto ou vídeo longo não serve');
fim(__FILE__);
