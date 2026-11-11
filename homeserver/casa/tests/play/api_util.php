<?php
declare(strict_types=1);

// Utilitário dos testes da API do Player (scripts simples, sem banco): php tests/play/api_todos.php
require_once dirname(__DIR__, 2) . '/src/autoload.php';

putenv('TZ=America/Sao_Paulo');
date_default_timezone_set('America/Sao_Paulo');
putenv('CASA_TOKEN=token-de-teste-da-casa');

$GLOBALS['falhas'] = 0;
$GLOBALS['passou'] = 0;

function ok(bool $cond, string $msg): void
{
    if ($cond) {
        $GLOBALS['passou']++;
        return;
    }
    $GLOBALS['falhas']++;
    echo "  FALHOU: $msg\n";
}

function igual(mixed $esperado, mixed $obtido, string $msg): void
{
    $ok = $esperado === $obtido;
    if (!$ok) {
        $msg .= "\n    esperado: " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n    obtido:   " . json_encode($obtido, JSON_UNESCAPED_UNICODE);
    }
    ok($ok, $msg);
}

function lanca(callable $fn, string $classe, string $msg): void
{
    try {
        $fn();
        ok(false, "$msg (não lançou)");
    } catch (\Throwable $e) {
        ok($e instanceof $classe, "$msg (lançou " . get_class($e) . ': ' . $e->getMessage() . ')');
    }
}

function fim(string $nome): void
{
    echo sprintf("%-22s %3d ok, %d falha(s)\n", $nome, $GLOBALS['passou'], $GLOBALS['falhas']);
    if (!defined('API_TODOS')) {
        exit($GLOBALS['falhas'] ? 1 : 0);
    }
}
