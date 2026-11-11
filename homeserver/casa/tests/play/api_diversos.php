<?php
declare(strict_types=1);

// Regras puras espalhadas: terminado, abertura, preferências, datas, SSE, episódios, painel.
require_once __DIR__ . '/api_util.php';

use Casa\Play\Api\Avisos;
use Casa\Play\Api\Base;
use Casa\Play\Api\Erro;
use Casa\Play\Api\Inicio;
use Casa\Play\Api\PainelPlayer;
use Casa\Play\Api\Sinal;
use Casa\Play\Api\Titulos;
use Casa\Play\Api\Tvs;
use Casa\Play\Api\Uso;

// Terminado (v10): fim, créditos ou dentro dos créditos.
ok(Uso::terminou('m', 'fim', 10, 7200), 'evento fim');
ok(Uso::terminou('s', 'creditos', 10, 2600), 'evento créditos');
ok(Uso::terminou('m', 'tick', 6850, 7200), 'filme a 5 min do fim (créditos de 6 min)');
ok(!Uso::terminou('m', 'tick', 6700, 7200), 'filme antes dos créditos');
ok(Uso::terminou('s', 'pausa', 2500, 2600), 'episódio a 100 s do fim');
ok(!Uso::terminou('s', 'tick', 100, 0), 'sem duração não termina');

// Abertura.
ok(Uso::aberturaValida(62, 152), 'abertura normal');
ok(!Uso::aberturaValida(62, 70), 'curta demais');
ok(!Uso::aberturaValida(0, 300), 'longa demais');
ok(!Uso::aberturaValida(500, 560), 'começa tarde demais');
ok(!Uso::aberturaValida(100, 90), 'fim antes do início');

// Preferências.
igual(true, Uso::validarPreferencia('legenda_auto', true), 'legenda_auto');
igual(false, Uso::validarPreferencia('esconder_fora_lista', 0), 'esconder_fora_lista falso');
igual('g', Uso::validarPreferencia('legenda_tamanho', 'g'), 'tamanho');
igual(-1.5, Uso::validarPreferencia('legenda_atraso:123:Interstellar.2014.srt', '-1.53'), 'atraso por título e arquivo');
igual(null, Uso::validarPreferencia('trailer_destaque', null), 'null apaga');
lanca(fn() => Uso::validarPreferencia('legenda_tamanho', 'xg'), Erro::class, 'tamanho inválido');
lanca(fn() => Uso::validarPreferencia('qualquer', 1), Erro::class, 'chave desconhecida');
lanca(fn() => Uso::validarPreferencia('legenda_atraso:abc:x', 1), Erro::class, 'atraso sem título numérico');
lanca(fn() => Uso::validarPreferencia('legenda_atraso:1:x', 9999), Erro::class, 'atraso absurdo');

// Rótulos de data (Suas séries).
$hoje = new DateTimeImmutable('2026-11-08 15:00', new DateTimeZone('America/Sao_Paulo'));   // domingo
igual('Hoje', Inicio::rotuloData('2026-11-08', $hoje), 'hoje');
igual('Amanhã', Inicio::rotuloData('2026-11-09', $hoje), 'amanhã');
igual('Sexta', Inicio::rotuloData('2026-11-13', $hoje), 'dia da semana até 6 dias');
igual('21/11', Inicio::rotuloData('2026-11-21', $hoje), 'data depois de uma semana');
igual([3, 5, 7], Inicio::ids([3, '5', ['id' => 7], 0, 'x', null]), 'ids das fileiras');

// Avisos.
igual('2026-11-08T20:55:00-03:00', Avisos::quandoAvisar('2026-11-08 21:00:00-03'), 'jogo: 5 min antes');
igual("Ação", Avisos::utf8("A\xE7\xE3o"), 'legenda Windows-1252 vira UTF-8');
igual("1\n", Avisos::utf8("\xEF\xBB\xBF1\n"), 'tira o BOM');

// SSE.
igual("id: 9\nevent: progresso\ndata: {\"titulo\":1,\"nome\":\"Ação\"}\n\n", Sinal::formatar('progresso', ['titulo' => 1, 'nome' => 'Ação'], 9), 'evento SSE com id');
igual("event: ping\ndata: {}\n\n", Sinal::formatar('ping', []), 'ping vazio');
igual("event: avisoxss\ndata: {}\n\n", Sinal::formatar("aviso\nxss", []), 'nome de evento sem quebra de linha');

// Episódios.
$eps = [['ep_id' => 1, 'temporada' => 1, 'numero' => 1, 'nome' => 'a'], ['ep_id' => 2, 'temporada' => 1, 'numero' => 2, 'nome' => 'b'],
        ['ep_id' => 3, 'temporada' => 2, 'numero' => 1, 'nome' => 'c']];
igual(2, Titulos::proximo($eps, 1, 1)['ep_id'], 'próximo na mesma temporada');
igual(3, Titulos::proximo($eps, 1, 2)['ep_id'], 'próximo vira a temporada');
igual(null, Titulos::proximo($eps, 2, 1), 'último episódio');
igual('hls', Titulos::formato('m3u8'), 'formato hls');
igual('mkv', Titulos::formato('MKV'), 'formato mkv');
igual('mp4', Titulos::formato('avi'), 'formato padrão');

// Perfis.
igual(['Thais', '#1E1B4B'], Tvs::validarPerfil('  Thais ', '#1e1b4b'), 'perfil válido');
lanca(fn() => Tvs::validarPerfil('', '#000000'), Erro::class, 'nome vazio');
lanca(fn() => Tvs::validarPerfil('Nome', 'red'), Erro::class, 'cor fora do formato');
igual(['id' => 1, 'nome' => 'casa', 'cor' => '#3B0A17', 'inicial' => 'C'], Tvs::perfil(['id' => '1', 'nome' => 'casa', 'cor' => '#3B0A17']), 'perfil no formato do contrato');

// Painel.
igual('http://painel.exemplo', PainelPlayer::validarServidor(' http://painel.exemplo/ '), 'servidor sem barra no fim');
igual('http://1.2.3.4:8080', PainelPlayer::validarServidor('http://1.2.3.4:8080'), 'servidor com porta');
lanca(fn() => PainelPlayer::validarServidor('painel.exemplo'), InvalidArgumentException::class, 'servidor sem http');
lanca(fn() => PainelPlayer::validarServidor('http://x.com/?a=1'), InvalidArgumentException::class, 'servidor com query');
igual('52 s', PainelPlayer::duracaoMs(52000), 'duração em segundos');
igual('3 min 05 s', PainelPlayer::duracaoMs(185000), 'duração em minutos');
igual('2 h 05', PainelPlayer::duracaoMs(7500000), 'duração em horas');
igual('1 h 31 min', PainelPlayer::horas(5460), 'horas assistidas');
igual('45 min', PainelPlayer::horas(2700), 'minutos assistidos');
[$ini, $fim, $ant] = PainelPlayer::mes('2026-03');
igual(['2026-03-01', '2026-04-01', '2026-02-01'], [$ini->format('Y-m-d'), $fim->format('Y-m-d'), $ant->format('Y-m-d')], 'janela do mês');
igual('março/2026', PainelPlayer::nomeMes($ini), 'nome do mês');
igual(date('Y-m') . '-01', PainelPlayer::mes('2026-13')[0]->format('Y-m-d'), 'mês inválido = mês atual');

// "Visto" (09/11/2026 noite): terminado ou Gostei; Não gostei não é visto (só sai das sugestões).
ok(Base::visto(true, 0), 'terminado = visto');
ok(Base::visto(false, 1), 'Gostei = visto (sem histórico)');
ok(!Base::visto(false, -1), 'Não gostei não é visto');
ok(!Base::visto(false, 0), 'sem nada não é visto');
ok(Base::visto(true, -1), 'terminado com Não gostei continua visto');
ok(str_contains(Base::VISTOS, 'play.nota') && substr_count(Base::VISTOS, '?') === count(Base::vistosPar(7)), 'VISTOS: nota na união e 3 parâmetros');
// Sugestões prontas sem os vistos/avaliados (vale antes do recálculo)
$rec = ['destaque' => [1, 2, 3], 'recomendados' => [2, 4, 5], 'porque' => [['titulo_id' => 2, 'nome' => 'X', 'ids' => [2, 6, 7]]],
        'generos' => [['nome' => 'Drama', 'ids' => [5, 8]]], 'epoca' => ['titulo' => 'Natal', 'ids' => [9, 2]],
        'por_tipo' => ['m' => ['recomendados' => [2, 4], 'generos' => [['nome' => 'Ação', 'ids' => [2, 10]]]]], 'gosto' => ['generos' => []]];
$s = Inicio::semVistos($rec, [2 => true, 5 => true]);
igual([1, 3], $s['destaque'], 'destaque sem o Gostei/Não gostei');
igual([4], $s['recomendados'], 'recomendados sem os avaliados');
igual(['titulo_id' => 2, 'nome' => 'X', 'ids' => [6, 7]], $s['porque'][0], '"porque": a origem fica, os itens saem');
igual([8], $s['generos'][0]['ids'], 'gêneros sem os avaliados');
igual([9], $s['epoca']['ids'], 'época sem os avaliados');
igual([4], $s['por_tipo']['m']['recomendados'], 'por tipo: recomendados');
igual([10], $s['por_tipo']['m']['generos'][0]['ids'], 'por tipo: gêneros');
igual($rec, Inicio::semVistos($rec, []), 'nada a tirar: igual');

fim('api_diversos');
