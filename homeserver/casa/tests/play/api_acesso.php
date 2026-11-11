<?php
declare(strict_types=1);

// Autenticação das TVs: token (Bearer ou ?t=), hash, código, barreira de aprovação.
require_once __DIR__ . '/api_util.php';

use Casa\Play\Api\Acesso;
use Casa\Play\Api\Contexto;
use Casa\Play\Api\Erro;

$tok = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4';
igual($tok, Acesso::token(['HTTP_AUTHORIZATION' => "Bearer $tok"], []), 'token pelo cabeçalho Bearer');
igual($tok, Acesso::token(['HTTP_AUTHORIZATION' => "bearer   $tok"], []), 'Bearer sem diferenciar maiúsculas');
igual($tok, Acesso::token(['REDIRECT_HTTP_AUTHORIZATION' => "Bearer $tok"], []), 'cabeçalho depois de rewrite do Apache');
igual($tok, Acesso::token([], ['t' => $tok]), 'token por ?t= (EventSource e <img>)');
igual('cabecalho1', Acesso::token(['HTTP_AUTHORIZATION' => 'Bearer cabecalho1'], ['t' => 'query12345']), 'cabeçalho vence a query');
igual(null, Acesso::token([], []), 'sem token');
igual(null, Acesso::token(['HTTP_AUTHORIZATION' => 'Basic dXNlcjpzZW5oYQ=='], []), 'Basic não é token');
igual(null, Acesso::token([], ['t' => 'curto']), 'token curto demais');
igual(null, Acesso::token([], ['t' => ['array']]), 'token em array é ignorado');

igual(hash('sha256', $tok), Acesso::hash($tok), 'hash sha256 (é o que fica em play.tv.token_hash)');
igual(64, strlen(Acesso::hash($tok)), 'hash com 64 caracteres');
$novo = Acesso::novoToken();
ok((bool) preg_match('/^[0-9a-f]{48}$/', $novo), 'token novo: 48 hex');
ok($novo !== Acesso::novoToken(), 'tokens diferentes a cada registro');
for ($i = 0; $i < 50; $i++) {
    $c = Acesso::novoCodigo();
    if (!preg_match('/^[A-HJKMNP-Z2-9]{4}$/', $c)) {
        ok(false, "código com caractere ambíguo: $c");
        break;
    }
}
ok(true, 'códigos de 4 caracteres sem 0/O/1/I/L');

igual(null, Acesso::barreira(['status' => 'ativa', 'codigo' => 'AB12']), 'TV ativa passa');
putenv('PLAY_APROVACAO');
$e = Acesso::barreira(['status' => 'pendente', 'codigo' => 'AB12']);
ok($e !== null && $e->getCode() === 403, 'pendente barrada (aprovação ligada por padrão)');
putenv('PLAY_APROVACAO=0');
igual(null, Acesso::barreira(['status' => 'pendente', 'codigo' => 'AB12']), 'pendente passa com PLAY_APROVACAO=0');
putenv('PLAY_APROVACAO');
ok(\Casa\Play\Api\Tvs::ipLocal('192.168.1.57') && \Casa\Play\Api\Tvs::ipLocal('100.124.1.70') && \Casa\Play\Api\Tvs::ipLocal('10.0.0.2')
    && \Casa\Play\Api\Tvs::ipLocal('172.20.0.1') && \Casa\Play\Api\Tvs::ipLocal('127.0.0.1'), 'IPs de casa aceitos no registro');
ok(!\Casa\Play\Api\Tvs::ipLocal('8.8.8.8') && !\Casa\Play\Api\Tvs::ipLocal('172.32.0.1') && !\Casa\Play\Api\Tvs::ipLocal('100.128.0.1')
    && !\Casa\Play\Api\Tvs::ipLocal('') && !\Casa\Play\Api\Tvs::ipLocal('2001:db8::1'), 'IPs de fora recusados no registro');
$e = Acesso::barreira(['status' => 'bloqueada', 'codigo' => 'AB12']);
ok($e instanceof Erro && $e->getCode() === 403 && ($e->extra['bloqueada'] ?? false), 'bloqueada = 403 com marca');
igual(401, Acesso::barreira(['status' => 'outra', 'codigo' => 'X'])->getCode(), 'situação desconhecida = 401');

ok(Acesso::ipDeCasa('192.168.1.51'), 'IP de casa 192.168');
ok(Acesso::ipDeCasa('10.0.0.5'), 'IP de casa 10.x');
ok(Acesso::ipDeCasa('172.20.1.2'), 'IP de casa 172.16-31');
ok(!Acesso::ipDeCasa('172.32.1.2'), '172.32 não é privado');
ok(!Acesso::ipDeCasa('100.101.1.2'), 'Tailscale não corrige IP de aparelho');
ok(!Acesso::ipDeCasa('::1'), 'IPv6 local não corrige');
ok(!Acesso::ipDeCasa('8.8.8.8'), 'IP público não corrige');

igual('publico', (new ReflectionClass(Contexto::class))->getShortName() === 'Contexto' ? 'publico' : '', 'contexto existe');
$ctx = new Contexto(tv: ['id' => '7', 'nome' => 'TV 2']);
igual(7, $ctx->tvId(), 'id da TV no contexto');
igual('TV 2', $ctx->tvNome(), 'nome da TV no contexto');
ok(!$ctx->ehAdmin(), 'TV não é admin');
ok((new Contexto(integracao: true))->ehAdmin(), 'token da casa é admin');
ok((new Contexto(usuario: ['perfil' => 'admin']))->ehAdmin(), 'usuário admin');

// Autenticar rota pública não exige nada.
ok(Acesso::autenticar('publico', 'GET') instanceof Contexto, 'rota pública sem token');
// Token da casa na query vale para integração e para as rotas da TV.
$_GET = ['t' => 'token-de-teste-da-casa'];
ok(Acesso::autenticar('integracao', 'POST')->integracao, 'CASA_TOKEN em rota de integração');
ok(Acesso::autenticar('tv', 'GET')->integracao, 'CASA_TOKEN também lê rotas da TV');
$_GET = [];
lanca(fn() => Acesso::autenticar('tv', 'GET'), Erro::class, 'sem token nem sessão = 401');
$_GET = ['t' => 'token-que-nao-e-da-casa'];
lanca(fn() => Acesso::autenticar('integracao', 'POST'), Erro::class, 'token de TV não abre integração');
$_GET = [];

fim('api_acesso');
