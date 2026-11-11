<?php
declare(strict_types=1);

// Importação da v10: localStorage real (valores em texto JSON, como localStorage.getItem devolve).
require_once __DIR__ . '/api_util.php';

use Casa\Play\Api\Importar;

$t = 1762620000000;   // 08/11/2026 em ms
$dados = [
    'play_history' => json_encode([
        ['type' => 's', 'id' => 4512, 'tmdb' => 1399, 'title' => 'Game of Thrones', 'g' => [18], 'f' => 0.97, 'eps' => 12, 'ep' => 88123,
         'best' => 1, 'se' => ['s' => 3, 'e' => 9], 't' => $t],
        ['type' => 'm', 'id' => '99001', 'tmdb' => 0, 'title' => 'Interestelar', 'g' => [], 'f' => 0.46, 'eps' => 0, 'ep' => 0, 'best' => 0.46, 't' => $t - 86400000],
        ['type' => 'x', 'id' => 1, 't' => $t],                       // tipo inválido
        ['type' => 'm', 'id' => 'abc', 't' => $t],                   // id inválido
    ]),
    'play_progress' => json_encode([
        'm99001'      => ['p' => 4500.7, 'd' => 10140, 't' => $t - 86400000, 'title' => 'Interestelar', 'poster' => 'http://x/p.jpg', 'label' => 'Filme', 'kind' => 'movie', 'id' => 99001, 'ext' => 'mkv'],
        'e88124'      => ['p' => 600, 'd' => 3300, 't' => $t, 'title' => 'Game of Thrones T3:E10', 'label' => 'T3:E10', 'kind' => 'series', 'id' => 88124, 'ext' => 'mp4',
                          'seriesId' => 4512, 'season' => 3, 'episode' => 10],
        'e70000'      => ['p' => 120, 'd' => 2500, 't' => $t, 'title' => 'Antiga'],          // sem seriesId (antes do histórico)
        's4512last'   => ['epId' => 88124, 't' => $t, 'p' => 0, 'd' => 0],                     // marcador
        'm5'          => ['p' => 10, 'd' => 5000, 't' => $t],                                  // menos de 30 s
    ]),
    'my_list' => json_encode([['type' => 'm', 'id' => 99002, 't' => $t], ['type' => 's', 'id' => '4600', 't' => $t], ['type' => 'z', 'id' => 1]]),
    'play_ratings' => json_encode(['m99001' => ['v' => 1, 't' => $t], 's4512' => ['v' => -1, 't' => $t], 'm7' => ['v' => 0, 't' => $t]]),
    'play_intros' => json_encode(['4512' => ['s' => 62, 'e' => 152, 't' => $t], 'abc' => ['s' => 1, 'e' => 2]]),
    'play_versions' => json_encode(['s4512' => 'leg', 'm1' => 'xx']),
    'live_favs' => json_encode(['301', '302', '301', 'x']),
    'subs_pref' => json_encode('pt-br'),
    'subs_size' => json_encode('g'),
    'subs_delay' => json_encode(['m99001' => ['f' => 'Interstellar.2014.1080p.srt', 'd' => -1.5, 't' => $t], 'e88124' => ['f' => '../../etc', 'd' => 2, 't' => $t]]),
    'continue_hidden' => json_encode(['m99001' => $t + 1000, 'pe70000' => $t + 5, 's4512' => 'x']),
    'tmdb_map' => json_encode(['m99001' => 157336, 's4512' => 1399, 'q1' => 5]),
    'outra_chave' => 'ignorada',
];

$n = Importar::interpretar($dados);

igual(2, count($n['historico']), 'histórico: 2 válidos de 4');
igual(['tipo' => 's', 'painel' => 4512, 'tmdb' => 1399, 'quando' => $t, 'temporada' => 3, 'episodio' => 9, 'ep' => 88123, 'fracao' => 0.97, 'terminado' => true],
    $n['historico'][0], 'série no histórico: último episódio visto até o fim');
igual(157336, $n['historico'][1]['tmdb'], 'tmdb 0 do histórico vem do tmdb_map');
igual(false, $n['historico'][1]['terminado'], 'filme pela metade não terminou');

igual(3, count($n['progresso']), 'progresso: filme + 2 episódios (marcador e < 30 s fora)');
igual(['tipo' => 'm', 'painel' => 99001, 'serie' => null, 'temporada' => null, 'episodio' => null, 'pos' => 4500, 'dur' => 10140, 'quando' => $t - 86400000],
    $n['progresso'][0], 'posição do filme');
igual(['tipo' => 'e', 'painel' => 88124, 'serie' => 4512, 'temporada' => 3, 'episodio' => 10, 'pos' => 600, 'dur' => 3300, 'quando' => $t],
    $n['progresso'][1], 'posição do episódio com série');
igual(null, $n['progresso'][2]['serie'], 'episódio antigo sem série (resolvido pela tabela de episódios)');

igual([['tipo' => 'm', 'painel' => 99002, 'quando' => $t], ['tipo' => 's', 'painel' => 4600, 'quando' => $t]], $n['lista'], 'Minha lista');
igual([['tipo' => 'm', 'painel' => 99001, 'valor' => 1, 'quando' => $t], ['tipo' => 's', 'painel' => 4512, 'valor' => -1, 'quando' => $t]], $n['notas'], 'notas (0 ignorada)');
igual([['serie' => 4512, 'inicio' => 62, 'fim' => 152]], $n['aberturas'], 'aberturas');
igual([['tipo' => 's', 'painel' => 4512, 'idioma' => 'leg']], $n['versoes'], 'versões (valor inválido ignorado)');
igual([301, 302], $n['favoritos'], 'favoritos sem repetidos, na ordem');
igual(['legenda_auto' => true, 'legenda_tamanho' => 'g'], $n['prefs'], 'preferências de legenda');
igual([['tipo' => 'm', 'painel' => 99001, 'arquivo' => 'Interstellar.2014.1080p.srt', 'atraso' => -1.5]], $n['atrasos'], 'atraso de legenda (nome de arquivo estranho ignorado)');
igual([['tipo' => 'm', 'painel' => 99001, 'quando' => $t + 1000], ['tipo' => 'e', 'painel' => 70000, 'quando' => $t + 5]], $n['ocultos'], 'tirados do Continuar');
igual(['m99001' => 157336, 's4512' => 1399], $n['tmdb'], 'tmdb_map (chave inválida fora)');

// Valores já decodificados (a TV pode mandar parseado) dão o mesmo resultado.
$parseado = array_map(fn($v) => is_string($v) ? (json_decode($v, true) ?? $v) : $v, $dados);
igual($n, Importar::interpretar($parseado), 'aceita valores já decodificados');

// Vazio ou lixo não quebra.
$vazio = Importar::interpretar([]);
igual(0, array_sum(array_map('count', $vazio)), 'dados vazios');
$lixo = Importar::interpretar(['play_history' => '{quebrado', 'my_list' => 5, 'play_progress' => 'null']);
igual(0, count($lixo['historico']) + count($lixo['lista']) + count($lixo['progresso']), 'JSON quebrado é ignorado');

fim('api_importar');
