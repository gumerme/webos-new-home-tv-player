<?php
declare(strict_types=1);

/**
 * Base dos testes do worker do Player (scripts simples, sem framework).
 * Rodar: php tests/play/rodar.php   (ou um arquivo só: php tests/play/texto_test.php)
 */

require_once dirname(__DIR__, 2) . '/src/autoload.php';
date_default_timezone_set('America/Sao_Paulo');

function dados(string $arq): array
{
    return json_decode((string) file_get_contents(__DIR__ . "/dados/$arq"), true);
}

$GLOBALS['_falhas'] = 0;
$GLOBALS['_ok'] = 0;

function igual(mixed $esperado, mixed $obtido, string $nome): void
{
    if ($esperado === $obtido) {
        $GLOBALS['_ok']++;
        return;
    }
    $GLOBALS['_falhas']++;
    fwrite(STDOUT, "  FALHOU: $nome\n    esperado: " . var_export($esperado, true) . "\n    obtido:   " . var_export($obtido, true) . "\n");
}

function verdade(bool $cond, string $nome): void
{
    igual(true, $cond, $nome);
}

function fim(string $arquivo): void
{
    static $ok0 = 0, $f0 = 0;
    $f = $GLOBALS['_falhas'];
    fwrite(STDOUT, sprintf("%s: %d ok, %d falha(s)\n", basename($arquivo), $GLOBALS['_ok'] - $ok0, $f - $f0));
    [$ok0, $f0] = [$GLOBALS['_ok'], $f];
    if (!defined('PLAY_TESTE_TODOS')) {
        exit($f ? 1 : 0);
    }
}

/** Segredos da v10 (config.js) só em memória, para os testes de fumaça. Nunca imprimir. */
function segredosV10(): array
{
    $arq = getenv('PLAY_CONFIG_JS') ?: '';
    $txt = @file_get_contents($arq);
    if ($txt === false || !preg_match('/PLAY_CONFIG\s*=\s*(\{.*?\});/s', $txt, $m)) {
        throw new RuntimeException('config.js da v10 não encontrado (PLAY_CONFIG_JS)');
    }
    $c = json_decode($m[1], true) ?: [];
    foreach (['username', 'password', 'tmdbKey'] as $k) {
        Casa\Play\Dados\Http::segredo($c[$k] ?? null);
    }
    return $c;
}
