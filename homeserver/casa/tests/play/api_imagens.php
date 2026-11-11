<?php
declare(strict_types=1);

// Imagens: validação de caminho (sem travessia), assinatura das imagens do painel e tipo pelo conteúdo.
require_once __DIR__ . '/api_util.php';

use Casa\Play\Api\Base;
use Casa\Play\Api\Imagens;

igual(['tipo' => 'tmdb', 'pasta' => 'w342', 'nome' => 'abc_DEF-1.jpg'], Imagens::validar('w342', 'abc_DEF-1.jpg'), 'arquivo TMDB válido');
igual(['tipo' => 'tmdb', 'pasta' => 'original', 'nome' => 'x.svg'], Imagens::validar('original', 'x.svg'), 'logo svg em original');
foreach (['w500', 'W342', '', '..', 'painel2', 'w342/..'] as $tam) {
    igual(null, Imagens::validar($tam, 'abc.jpg'), "tamanho recusado: '$tam'");
}
foreach (['../segredo', '..%2fx.jpg', '.htaccess', 'a/b.jpg', 'a\\b.jpg', 'x.php', 'x.jpg.php', 'x.jpg ', "x\0.jpg", str_repeat('a', 81) . '.jpg', ''] as $arq) {
    igual(null, Imagens::validar('w342', $arq), 'arquivo recusado: ' . json_encode($arq));
}
$h = str_repeat('0', 40);
igual(['tipo' => 'painel', 'pasta' => 'painel', 'nome' => $h], Imagens::validar('painel', $h), 'hash do painel');
igual(null, Imagens::validar('painel', '../' . substr($h, 3)), 'hash com travessia');
igual(null, Imagens::validar('painel', strtoupper(str_repeat('a', 40))), 'hash só minúsculo');
igual(null, Imagens::validar('painel', str_repeat('a', 39)), 'hash curto');

// Assinatura: só baixa a URL que o próprio servidor entregou.
$url = 'http://painel.exemplo:8080/images/abc.png';
$rel = Base::img($url);
parse_str((string) parse_url($rel, PHP_URL_QUERY), $q);
$hash = basename((string) parse_url($rel, PHP_URL_PATH));
igual($url, Imagens::origemPainel($hash, $q['u']), 'URL assinada confere');
igual(null, Imagens::origemPainel(str_repeat('b', 40), $q['u']), 'hash de outra URL recusado');
$outra = rtrim(strtr(base64_encode('http://169.254.169.254/latest/meta-data/'), '+/', '-_'), '=');
igual(null, Imagens::origemPainel($hash, $outra), 'não vira proxy para outra URL');
igual(null, Imagens::origemPainel($hash, ''), 'sem u');
igual(null, Imagens::origemPainel($hash, 'a+b/c='), 'u fora do base64url');
$ftp = 'ftp://x/a.png';
igual(null, Imagens::origemPainel(Base::assinar($ftp), rtrim(strtr(base64_encode($ftp), '+/', '-_'), '=')), 'só http(s)');
putenv('CASA_TOKEN=outro-token');
igual(null, Imagens::origemPainel($hash, $q['u']), 'assinatura muda com o token da casa');
putenv('CASA_TOKEN=token-de-teste-da-casa');

// Tipo pelo conteúdo.
igual('image/jpeg', Imagens::tipoPorConteudo("\xFF\xD8\xFF\xE0abc"), 'jpeg');
igual('image/png', Imagens::tipoPorConteudo("\x89PNG\r\n\x1A\n...."), 'png');
igual('image/webp', Imagens::tipoPorConteudo('RIFF1234WEBPVP8 '), 'webp');
igual('image/gif', Imagens::tipoPorConteudo('GIF89a'), 'gif');
igual('image/svg+xml', Imagens::tipoPorConteudo('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>'), 'svg');
igual(null, Imagens::tipoPorConteudo('<html><body>erro 404</body></html>'), 'página de erro não é imagem');
igual(null, Imagens::tipoPorConteudo(''), 'vazio');

fim('api_imagens');
