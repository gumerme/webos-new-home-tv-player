<?php
declare(strict_types=1);

// Setas do ranking: posição atual × última coleta do dia anterior.
require_once __DIR__ . '/api_util.php';

use Casa\Play\Api\Top10;

igual('NOVO', Top10::seta(null, 1), 'não estava ontem');
igual('▲2', Top10::seta(5, 3), 'subiu 2');
igual('▼1', Top10::seta(2, 3), 'caiu 1');
igual('=', Top10::seta(4, 4), 'igual');

igual('t10', Top10::chave(['titulo_id' => 10, 'jw_id' => 'tm1', 'tmdb_id' => 5]), 'chave pelo título da lista');
igual('jtm1', Top10::chave(['titulo_id' => null, 'jw_id' => 'tm1', 'tmdb_id' => 5]), 'chave pelo JustWatch');
igual('d5', Top10::chave(['titulo_id' => null, 'jw_id' => null, 'tmdb_id' => 5]), 'chave pelo TMDB');
igual('nmatrix', Top10::chave(['titulo_id' => null, 'jw_id' => null, 'tmdb_id' => null, 'nome' => ' Matrix ']), 'chave pelo nome');

$l = fn($cod, $pos, $tit, $jw = null, $tipo = 's') => ['tipo' => $tipo, 'codigo' => $cod, 'posicao' => $pos, 'titulo_id' => $tit, 'jw_id' => $jw, 'tmdb_id' => null, 'nome' => "x$pos"];
$hoje = [$l('nfx', 1, 10), $l('nfx', 2, 11), $l('nfx', 3, null, 'tmA'), $l('nfx', 4, 13), $l('prv', 1, 10), $l('dnp', 1, 20)];
$ontem = [$l('nfx', 1, 11), $l('nfx', 2, 13), $l('nfx', 3, 10), $l('nfx', 5, null, 'tmA'), $l('prv', 1, 10), $l('nfx', 1, 99, null, 'm')];
$s = Top10::setas($hoje, $ontem);
igual('▲2', $s['s|nfx|t10'], 'título 10 subiu de 3 para 1');
igual('▼1', $s['s|nfx|t11'], 'título 11 caiu de 1 para 2');
igual('▲2', $s['s|nfx|jtmA'], 'fora da lista comparado pelo JustWatch (5 -> 3)');
igual('▼2', $s['s|nfx|t13'], 'título 13 caiu de 2 para 4');
igual('=', $s['s|prv|t10'], 'mesma posição em outra plataforma');
igual(null, $s['s|dnp|t20'], 'plataforma sem coleta ontem fica sem seta');
ok(!isset($s['m|nfx|t99']), 'filmes não se misturam com séries');

$hoje2 = [$l('nfx', 1, 50)];
igual('NOVO', Top10::setas($hoje2, $ontem)['s|nfx|t50'], 'não estava no Top 10 de ontem');

fim('api_ranking');
