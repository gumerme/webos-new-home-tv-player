<?php
declare(strict_types=1);

// Recomendador::completar: "Para você" de um tipo completa até N com as fileiras de gênero, intercalando. Puro.
require_once __DIR__ . '/_base.php';

use Casa\Play\Dados\Recomendador;

$fileiras = [['ids' => [10, 11, 12]], ['ids' => [20, 10, 21]]];
igual([1, 2, 3, 4, 5], Recomendador::completar([1, 2, 3, 4, 5], $fileiras, 5), 'lista cheia não muda');
igual([1, 10, 20, 11, 12, 21], Recomendador::completar([1], $fileiras, 10), 'intercala as fileiras sem repetir');
igual([1, 10, 20], Recomendador::completar([1], $fileiras, 3), 'para no limite');
igual([10, 20, 11], Recomendador::completar([], $fileiras, 3), 'sem recomendados do tipo: só as fileiras');
igual([], Recomendador::completar([], [], 40), 'nada para completar');
fim(__FILE__);
