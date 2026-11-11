<?php
declare(strict_types=1);

// Roda todos os testes do worker do Player: php tests/play/rodar.php
define('PLAY_TESTE_TODOS', true);
require_once __DIR__ . '/_base.php';

foreach (glob(__DIR__ . '/*_test.php') as $arq) {
    require $arq;
}
$f = $GLOBALS['_falhas'];
fwrite(STDOUT, sprintf("\nTOTAL: %d ok, %d falha(s)\n", $GLOBALS['_ok'], $f));
exit($f ? 1 : 0);
