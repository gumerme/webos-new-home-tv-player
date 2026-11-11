<?php
declare(strict_types=1);

// Roteamento: caminho -> handler, parâmetros, acesso; cada handler existe.
require_once __DIR__ . '/api_util.php';

use Casa\Play\Api\Rotas;
use Casa\Play\Api\Pedido;

$casos = [
    ['GET', '/api/play/saude', 'Tvs::saude', [], 'publico'],
    ['POST', '/api/play/tv/registrar', 'Tvs::registrar', [], 'publico'],
    ['GET', '/api/play/inicio', 'Inicio::inicio', [], 'tv'],
    ['GET', '/api/play/categoria/12', 'Inicio::categoria', ['cat' => '12'], 'tv'],
    ['GET', '/api/play/categoria/jw:drm', 'Inicio::categoria', ['cat' => 'jw:drm'], 'tv'],
    ['GET', '/api/play/categoria/jw%3Acmy', 'Inicio::categoria', ['cat' => 'jw:cmy'], 'tv'],
    ['GET', '/api/play/titulo/987', 'Titulos::titulo', ['id' => '987'], 'tv'],
    ['GET', '/api/play/titulo/por-tmdb', 'Titulos::porTmdb', [], 'tv'],
    ['GET', '/api/play/titulo/987/temporadas', 'Titulos::temporadas', ['id' => '987'], 'tv'],
    ['GET', '/api/play/titulo/987/apos', 'Titulos::apos', ['id' => '987'], 'tv'],
    ['GET', '/api/play/pessoa/6193', 'Busca::pessoa', ['tmdb' => '6193'], 'tv'],
    ['POST', '/api/play/aviso/5/cancelar', 'Avisos::cancelar', ['id' => '5'], 'tv'],
    ['GET', '/api/play/legenda/77', 'Avisos::legenda', ['id' => '77'], 'tv'],
    ['GET', '/api/play/aovivo/tocar/321', 'AoVivo::tocar', ['canal' => '321'], 'tv'],
    ['GET', '/api/play/img/w342/abc.jpg', 'Imagens::rota', ['tam' => 'w342', 'arquivo' => 'abc.jpg'], 'publico'],
    ['GET', '/api/play/img/painel/' . str_repeat('a', 40), 'Imagens::rota', ['tam' => 'painel', 'arquivo' => str_repeat('a', 40)], 'publico'],
    ['GET', '/api/play/sinal', 'Sinal::rota', [], 'tv'],
    ['GET', '/api/play/minhas-series', 'Tvs::minhasSeries', [], 'integracao'],
    ['POST', '/api/play/continuar', 'Tvs::continuar', [], 'integracao'],
    ['POST', '/api/play/continuar-ocultar', 'Uso::ocultar', [], 'tv'],
    ['GET', '/api/play/inicio/', 'Inicio::inicio', [], 'tv'],
];
foreach ($casos as [$m, $c, $h, $params, $acesso]) {
    $r = Rotas::resolver($m, $c);
    ok(is_array($r), "$m $c resolve");
    if (is_array($r)) {
        igual($h, substr(strrchr($r['handler'][0], '\\'), 1) . '::' . $r['handler'][1], "$m $c -> $h");
        igual($params, $r['params'], "$m $c parâmetros");
        igual($acesso, $r['acesso'], "$m $c acesso");
    }
}

igual(null, Rotas::resolver('GET', '/api/play/nada'), 'rota inexistente');
igual(null, Rotas::resolver('GET', '/api/play/'), 'raiz');
igual(null, Rotas::resolver('GET', '/api/dispositivos'), 'fora de /api/play');
igual('metodo', Rotas::resolver('GET', '/api/play/progresso'), 'existe só como POST');
igual('metodo', Rotas::resolver('DELETE', '/api/play/inicio'), 'método errado');
igual(null, Rotas::resolver('GET', '/api/play/titulo/abc'), 'id não numérico');
igual(null, Rotas::resolver('GET', '/api/play/titulo/0'), 'id zero');
igual(null, Rotas::resolver('GET', '/api/play/titulo/-1'), 'id negativo');
igual(null, Rotas::resolver('GET', '/api/play/titulo/12/temporadas/extra'), 'segmentos a mais');
// Travessia codificada chega decodificada ao handler (que recusa: ver api_imagens.php).
$r = Rotas::resolver('GET', '/api/play/img/w342/%2e%2e%2fsegredo');
igual('../segredo', $r['params']['arquivo'] ?? null, 'segmento decodificado para o handler validar');
igual(null, Rotas::resolver('GET', '/api/play/img/w342/a%00.jpg'), 'byte nulo recusado na rota');

// Todo handler da tabela existe e recebe Pedido.
foreach (Rotas::TABELA as [$m, $padrao, $handler]) {
    ok(method_exists($handler[0], $handler[1]), "handler existe: {$handler[0]}::{$handler[1]}");
    $ref = new ReflectionMethod($handler[0], $handler[1]);
    ok($ref->isStatic() && $ref->isPublic(), "handler público e estático: $padrao");
    $p = $ref->getParameters()[0] ?? null;
    ok($p && (string) $p->getType() === Pedido::class, "handler recebe Pedido: $padrao");
}

// Rotas do contrato (docs/play-2.0.md) todas presentes.
$contrato = ['POST tv/registrar', 'GET estado', 'GET conta', 'GET perfis', 'POST perfis', 'GET inicio', 'GET secao', 'GET suas-series',
    'GET categorias', 'GET categoria/{cat}', 'GET ranking', 'GET titulo/{id}', 'GET titulo/por-tmdb', 'GET titulo/{id}/temporadas', 'GET titulo/{id}/apos',
    'GET tocar', 'POST progresso', 'POST lista', 'POST nota', 'POST continuar-ocultar', 'POST versao', 'POST abertura', 'GET busca',
    'GET pessoa/{tmdb}', 'GET avisos', 'POST aviso', 'POST aviso/{id}/cancelar', 'GET legenda/{id}', 'GET legendas', 'POST preferencia',
    'GET preferencias', 'POST importar', 'GET aovivo/grupos', 'GET aovivo/jogos', 'GET aovivo/canais', 'GET aovivo/grade',
    'GET aovivo/tocar/{canal}', 'POST aovivo/favorito', 'GET sinal', 'GET minhas-series', 'POST continuar', 'GET saude', 'GET img/{tam}/{arquivo}'];
$tabela = array_map(fn($r) => $r[0] . ' ' . $r[1], Rotas::TABELA);
foreach ($contrato as $r) {
    ok(in_array($r, $tabela, true), "rota do contrato implementada: $r");
}

fim('api_rotas');
