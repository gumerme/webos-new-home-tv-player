<?php
declare(strict_types=1);

// "Continuar assistindo" com o último canal do Ao vivo (09/11/2026 noite): entra 1º ou 2º, só se < 24 h. Puro.
require_once __DIR__ . '/_base.php';

use Casa\Play\Api\Inicio;
use Casa\Play\Api\Launcher;

$agora = strtotime('2026-11-09T22:00:00-03:00');
$filme = ['id' => 10, 'nome' => 'Interestelar', 'quando' => '2026-11-09T21:00:00-03:00'];
$serie = ['id' => 11, 'nome' => 'Seinfeld', 'quando' => '2026-11-09T18:00:00-03:00'];
$canal = fn(string $q) => ['tipo' => 'canal', 'id' => 10, 'nome' => 'SporTV', 'quando' => $q];
$ids = fn(array $l) => array_map(fn($c) => ($c['tipo'] ?? 't') . $c['id'], $l);

igual(['canal10', 't10', 't11'], $ids(Inicio::encaixarCanal([$filme, $serie], $canal('2026-11-09T21:30:00-03:00'), $agora)), 'mais recente que o 1º título: entra em primeiro');
igual(['t10', 'canal10', 't11'], $ids(Inicio::encaixarCanal([$filme, $serie], $canal('2026-11-09T20:00:00-03:00'), $agora)), 'mais antigo que o 1º título: entra em segundo');
igual(['t10', 'canal10', 't11'], $ids(Inicio::encaixarCanal([$filme, $serie], $canal('2026-11-09T21:00:00-03:00'), $agora)), 'mesma hora: segundo (só passa à frente se for mais recente)');
igual(['t10', 'canal10', 't11'], $ids(Inicio::encaixarCanal([$filme, $serie], $canal('2026-11-09T10:00:00-03:00'), $agora)), 'mais antigo que todos (mas < 24 h): ainda segundo');
igual(['t10', 't11'], $ids(Inicio::encaixarCanal([$filme, $serie], $canal('2026-11-08T21:59:00-03:00'), $agora)), 'visto há mais de 24 h: fica de fora');
igual(['t10', 'canal10'], $ids(Inicio::encaixarCanal([$filme], $canal('2026-11-08T22:01:00-03:00'), $agora)), 'há 23 h 59 min: ainda entra (em segundo)');
igual(['canal10'], $ids(Inicio::encaixarCanal([], $canal('2026-11-09T21:30:00-03:00'), $agora)), 'Continuar vazio: só o canal');
igual(['canal10', 't10'], $ids(Inicio::encaixarCanal([['id' => 10]], $canal('2026-11-09T20:00:00-03:00'), $agora)), 'título sem data: canal em primeiro');
igual(['t10', 't11'], $ids(Inicio::encaixarCanal([$filme, $serie], null, $agora)), 'sem último canal: nada muda');
igual(['t10'], $ids(Inicio::encaixarCanal([$filme], $canal(''), $agora)), 'data vazia: fica de fora');
igual(['t10'], $ids(Inicio::encaixarCanal([$filme], $canal('lixo'), $agora)), 'data inválida: fica de fora');

verdade(Inicio::canalRecente('2026-11-09T21:59:00-03:00', $agora), 'canalRecente: 1 min atrás');
verdade(!Inicio::canalRecente('2026-11-08T22:00:00-03:00', $agora), 'canalRecente: exatamente 24 h = fora');

$c = Inicio::cartaoCanal(['id' => 7, 'nome' => 'ESPN', 'numero' => 31, 'logo' => '/api/play/img/w185/x.png', 'grupo' => 'Esportes', 'favorito' => true,
                          'agora' => ['titulo' => 'SportsCenter', 'inicio' => '2026-11-09T21:00:00-03:00', 'fim' => '2026-11-09T22:00:00-03:00', 'pct' => 50],
                          'qualidade' => 'fhd', 'xt' => ['id' => 7]], '2026-11-09T21:40:00-03:00');
igual(['tipo', 'id', 'nome', 'logo', 'numero', 'agora', 'quando', 'qualidade', 'xt'], array_keys($c), 'cartão de canal do Player: campos');
igual('canal', $c['tipo'], 'tipo canal');
igual(50, $c['agora']['pct'], 'programa de agora com pct no Player');
$l = Launcher::canal($c);
igual(['tipo' => 'canal', 'id' => 7, 'nome' => 'ESPN', 'numero' => 31, 'logo' => '/api/play/img/w185/x.png',
       'agora' => ['titulo' => 'SportsCenter', 'inicio' => '2026-11-09T21:00:00-03:00', 'fim' => '2026-11-09T22:00:00-03:00'],
       'quando' => $c['quando']], $l, 'cartão de canal da Home: enxuto, sem pct (não muda o "v" a cada minuto)');
igual(null, Launcher::canal(['id' => 8, 'nome' => 'Off', 'agora' => null])['agora'], 'Home: sem programa = agora null');
fim(__FILE__);
