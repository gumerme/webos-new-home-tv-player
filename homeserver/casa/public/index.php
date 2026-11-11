<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Player 2.0 (TVs): roteador próprio, com CORS para file:// (origem "null").
if (str_starts_with($caminho, '/api/play/')) {
    Casa\Play\Api\Roteador::atender($metodo, $caminho);
    return;
}

if (str_starts_with($caminho, '/api/')) {
    Casa\Api::atender($metodo, $caminho);
    return;
}

Casa\Painel::atender($metodo, rtrim($caminho, '/') ?: '/');
