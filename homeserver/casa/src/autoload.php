<?php
declare(strict_types=1);

// Autoload PSR-4 simples: Casa\Drivers\LgWebOs -> src/Drivers/LgWebOs.php
spl_autoload_register(static function (string $classe): void {
    if (!str_starts_with($classe, 'Casa\\')) {
        return;
    }
    $arquivo = __DIR__ . '/' . str_replace('\\', '/', substr($classe, 5)) . '.php';
    if (is_file($arquivo)) {
        require $arquivo;
    }
});

/** Escape para HTML (usado nas views). */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
