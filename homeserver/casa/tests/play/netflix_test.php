<?php
declare(strict_types=1);

// Netflix (Atividade de visualização): leitura do CSV, obras e peso. Puro.
require_once __DIR__ . '/_base.php';

use Casa\Play\Dados\Netflix;

$csv = "\xEF\xBB\xBFTitle,Date\n"
    . "\"Todo Mundo Odeia o Chris: Temporada 1: Todo mundo odeia basquete\",\"11/9/26\"\n"
    . "\"Todo Mundo Odeia o Chris: Temporada 1: Todo mundo odeia a Keisha\",\"11/9/26\"\n"
    . "\"Todo Mundo Odeia o Chris: Temporada 4: Todo mundo odeia lavar a louça\",\"11/8/26\"\n"
    . "\"Betty em Nova York: Una propuesta indecente\",\"11/9/26\"\n"
    . "\"Betty em Nova York: El gran regalo\",\"11/7/26\"\n"
    . "\"Betty em Nova York: La boda\",\"11/6/26\"\n"
    . "\"Missão: Impossível - Acerto de Contas\",\"3/2/25\"\n"
    . "\"Natal no Palácio\",\"11/9/26\"\n"
    . "\"Natal no Palácio\",\"12/24/25\"\n"
    . "\"Dahmer: Um Canibal Americano: Monstro: A História de Jeffrey Dahmer: Episódio um\",\"10/16/22\"\n"
    . "lixo sem data\n";
$l = Netflix::lerCsv($csv);
igual(10, count($l), 'lê as linhas válidas (BOM, cabeçalho e lixo fora)');
igual('2026-11-09', $l[0]['d'], 'data M/D/AA');
$o = array_column(Netflix::agrupar($l), null, 'n');
igual('s', $o['Todo Mundo Odeia o Chris']['t'] ?? null, '"Temporada N" = série');
igual(3, $o['Todo Mundo Odeia o Chris']['eps'], 'episódios contados');
igual(2, $o['Todo Mundo Odeia o Chris']['dias'], 'dias diferentes');
igual(2, $o['Todo Mundo Odeia o Chris']['maxdia'], 'maior número de episódios num dia');
igual('s', $o['Betty em Nova York']['t'] ?? null, 'novela sem temporada: prefixo repetido = série');
igual('m', $o['Missão: Impossível - Acerto de Contas']['t'] ?? null, 'filme com dois-pontos no nome continua filme');
igual(2, $o['Natal no Palácio']['dias'] ?? null, 'filme visto em dois dias');
igual('s', $o['Dahmer: Um Canibal Americano: Monstro: A História de Jeffrey Dahmer']['t'] ?? null, '"Episódio um" marca episódio (a busca encurta o nome depois)');

$agora = strtotime('2026-11-09 20:00:00');
$chris = Netflix::peso($o['Todo Mundo Odeia o Chris'], $agora);
$natal = Netflix::peso($o['Natal no Palácio'], $agora);
$missao = Netflix::peso($o['Missão: Impossível - Acerto de Contas'], $agora);
verdade($natal > $missao, 'revisto e recente pesa mais que visto uma vez há 1 ano e meio');
verdade($chris > 0.35, 'série recente com vários episódios pesa bem');
$maratona = ['t' => 's', 'eps' => 500, 'dias' => 60, 'maxdia' => 20, 'ult' => '2026-11-09'];
verdade(Netflix::peso($maratona, $agora) < \Casa\Play\Dados\Recomendador::pesoSinal(['t' => $agora, 'f' => 1.0, 'eps' => 0, 'tipo' => 'm'], $agora),
    'ponto de partida: nem a maior maratona da Netflix vale mais que um filme visto até o fim hoje no Player');
$velha = ['t' => 's', 'eps' => 20, 'dias' => 5, 'maxdia' => 5, 'ult' => '2026-07-09'];
verdade(Netflix::peso($velha, $agora) < 0.5 * Netflix::peso(['ult' => '2026-11-09'] + $velha, $agora), 'Netflix cai pela metade em 4 meses');
verdade(Netflix::peso(['t' => 's', 'eps' => 1, 'dias' => 1, 'maxdia' => 1, 'ult' => '2026-11-09'], $agora) < 0.3, '1 episódio = só experimentou');
verdade(Netflix::peso(['t' => 'm', 'eps' => 1, 'dias' => 1, 'maxdia' => 1, 'ult' => '2018-01-01'], $agora) >= 0.5 * 0.15 * 0.9 - 1e-9, 'gosto antigo não some (piso)');
fim(__FILE__);
