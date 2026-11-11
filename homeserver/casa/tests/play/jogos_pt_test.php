<?php
declare(strict_types=1);

// Tradução de liga e tempo de jogo da ESPN (Jogos::ligaPt / minutoPt). Puro.
require_once __DIR__ . '/_base.php';

use Casa\Play\Dados\Jogos;

igual('Série B', Jogos::ligaPt('Brazil Serie B'), 'Série B');
igual('Copa do Brasil · semifinal', Jogos::ligaPt('Copa Do Brazil, Semifinals'), 'Copa do Brasil com fase');
igual('Premier League', Jogos::ligaPt('English Premier League'), 'Premier League');
igual('LaLiga', Jogos::ligaPt('LALIGA'), 'LaLiga');
igual('Liga Qualquer', Jogos::ligaPt('Liga Qualquer'), 'desconhecida fica como veio');
igual(null, Jogos::ligaPt(''), 'vazia = null');
igual("90'+3'", Jogos::minutoPt("90'+3'"), 'minuto com acréscimo fica');
igual('Intervalo', Jogos::minutoPt('HT'), 'HT = Intervalo');
igual("Prorrogação 105'", Jogos::minutoPt("ET 105'"), 'prorrogação');
igual(null, Jogos::minutoPt(' '), 'vazio = null');
fim(__FILE__);
