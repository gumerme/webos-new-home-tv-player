<?php
declare(strict_types=1);

// "Jogos do Dia" do painel × placar da ESPN (canais e eventos reais de 08/11/2026).
require_once __DIR__ . '/_base.php';

use Casa\Play\Dados\Jogos;

$agora = mktime(16, 31, 0, 11, 8, 2026);                  // quando os dados foram guardados
$jogos = Jogos::canaisDeJogos(dados('canais_jogos.json'), $agora);
igual(12, count($jogos), 'um jogo por partida (FHD e HD juntos)');
$porTitulo = [];
foreach ($jogos as $j) {
    $porTitulo[$j['titulo']] = $j;
}
igual(900056, $porTitulo['América-MG x Ceará']['canal'], 'FHD preferido');
igual(['América-MG', 'Ceará'], $porTitulo['América-MG x Ceará']['times'], 'times');
igual(mktime(19, 15, 0, 11, 8, 2026), $porTitulo['América-MG x Ceará']['inicio'], 'início 19:15');
igual('agora', $porTitulo['Athletic-MG x Atlético-GO']['status'], 'sem ESPN: 16:00 está acontecendo às 16:31');
igual('fim', $porTitulo['Elche x Betis']['status'], 'sem ESPN: 10:00 acabou');
igual('agora', $jogos[0]['status'], 'acontecendo primeiro');

// madrugada: depois das 18 h, jogo de 0:30 é de amanhã
$m = Jogos::canaisDeJogos([['id' => 1, 'nome' => '00:30 - Flamengo x Grêmio [HD]']], mktime(20, 0, 0, 11, 8, 2026));
igual(mktime(0, 30, 0, 11, 9, 2026), $m[0]['inicio'], 'jogo da madrugada é amanhã');

// casamento com a ESPN
$j = Jogos::casar($jogos, dados('espn_eventos.json')['events'], $agora);
$porTitulo = [];
foreach ($j as $x) {
    $porTitulo[$x['titulo']] = $x;
}
$am = $porTitulo['América-MG x Ceará'];
verdade(!empty($am['espn']), 'América-MG x Ceará casou (ESPN tem a ordem invertida)');
igual(['AMG', 'CEA'], $am['espn']['siglas'], 'casa/fora seguem o painel');
igual('Série B', $am['espn']['liga'], 'liga em português');
$at = $porTitulo['Athletic-MG x Atlético-GO'];
igual('agora', $at['status'], 'Athletic ao vivo pela ESPN');
igual('1 x 0', $at['placar'], 'placar do Athletic');
verdade($at['minuto'] !== null, 'minuto');
$bar = $porTitulo['Atlético de Madrid x Barcelona'];
igual('Barcelona', $bar['espn']['nomes'][1], 'Barcelona da LALIGA, não o Barcelona SC (outro horário)');
igual('depois', $bar['status'], 'ainda não começou');
igual(null, $bar['placar'], 'sem placar antes do jogo');
$mu = $porTitulo['Manchester United x Aston Villa'];
igual('4 x 4', $mu['placar'], 'Man United 4 x 4 (masculino, não o feminino do mesmo dia)');
igual('fim', $mu['status'], 'encerrado');
igual(12, count(array_filter($j, fn($x) => !empty($x['espn']))), 'todos os 12 casados');

// pontos de nome
igual(3, Jogos::pontosTime('Ceará', ['displayName' => 'Ceará']), 'igual = 3');
igual(2, Jogos::pontosTime('RB Bragantino', ['displayName' => 'Red Bull Bragantino']), 'palavras = 2');
igual(2, Jogos::pontosTime('Athletic-MG', ['displayName' => 'Athletic']), 'Athletic-MG = Athletic');
igual(0, Jogos::pontosTime('Vasco', ['displayName' => 'Palmeiras']), 'diferente = 0');
igual('20261108', Jogos::diaEspn(mktime(19, 15, 0, 11, 8, 2026)), 'dia ESPN');
igual('https://a.espncdn.com/combiner/i?img=/i/teamlogos/soccer/500/9969.png&w=128&h=128', Jogos::urlEscudo('https://a.espncdn.com/i/teamlogos/soccer/500/9969.png'), 'escudo 128 px');
igual(null, Jogos::urlEscudo('https://a.espncdn.com/i/teamlogos/default.png'), 'escudo padrão = sem escudo');

fim(__FILE__);
