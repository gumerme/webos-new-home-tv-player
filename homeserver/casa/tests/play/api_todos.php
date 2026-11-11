<?php
declare(strict_types=1);

// Roda todos os testes da API do Player: php tests/play/api_todos.php (sem banco).
define('API_TODOS', true);
require_once __DIR__ . '/api_util.php';

$total = 0;
foreach (['api_rotas', 'api_acesso', 'api_cartao', 'api_ranking', 'api_importar', 'api_imagens', 'api_diversos'] as $t) {
    $GLOBALS['passou'] = 0;
    $antes = $GLOBALS['falhas'];
    require __DIR__ . "/$t.php";
    $total += $GLOBALS['passou'];
}
echo "\nTotal: $total ok, {$GLOBALS['falhas']} falha(s)\n";
exit($GLOBALS['falhas'] ? 1 : 0);
