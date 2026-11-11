<?php
declare(strict_types=1);

namespace Casa\Play\Api;

use Casa\HttpErro;

/**
 * Entrada de /api/play/*: CORS (as TVs chamam de file://, origem "null"), autenticação, despacho e JSON.
 * Despachado por public/index.php antes de Casa\Api.
 */
final class Roteador
{
    public static function atender(string $metodo, string $caminho): void
    {
        // CORS aberto: as TVs não mandam cookie; a sessão do painel continua exigindo X-CSRF na escrita.
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF, Last-Event-ID');
        header('Access-Control-Max-Age: 86400');
        header('Cross-Origin-Resource-Policy: cross-origin');
        if ($metodo === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $saida = 'json';
        try {
            $rota = Rotas::resolver($metodo, $caminho);
            if ($rota === null) {
                throw new Erro('rota não encontrada', 404);
            }
            if ($rota === 'metodo') {
                throw new Erro('método não permitido', 405);
            }
            $saida = $rota['saida'];
            $ctx = Acesso::autenticar($rota['acesso'], $metodo);
            $pedido = new Pedido($metodo, $rota['params'], $_GET, $metodo === 'GET' ? [] : self::corpo(), $ctx);
            $resposta = ($rota['handler'])($pedido);
            if ($saida === 'json') {
                self::json($pedido->status, $resposta ?? ['ok' => true]);
            }
        } catch (Erro $e) {
            self::falha($e->getCode() ?: 400, ['erro' => $e->getMessage()] + $e->extra);
        } catch (HttpErro $e) {
            self::falha($e->getCode() ?: 400, ['erro' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            self::falha(422, ['erro' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('API play: ' . $e);
            self::falha(500, ['erro' => 'erro interno']);
        }
    }

    public static function json(int $status, mixed $corpo): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        // A TV 2 é lenta e a rede às vezes também: o Início comprimido chega bem antes.
        if (PHP_SAPI !== 'cli' && !headers_sent() && extension_loaded('zlib') && !ini_get('zlib.output_compression')
            && str_contains((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip')
            && !in_array('ob_gzhandler', ob_list_handlers(), true)) {
            ob_start('ob_gzhandler');
        }
        echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function falha(int $status, array $corpo): void
    {
        if (headers_sent()) {
            // Stream já começou (SSE/imagem): só registra.
            error_log('API play: erro depois do início da resposta: ' . ($corpo['erro'] ?? ''));
            return;
        }
        header_remove('Content-Type');
        header_remove('Cache-Control');
        header_remove('ETag');
        self::json($status, $corpo);
    }

    private static function corpo(): array
    {
        $txt = (string) file_get_contents('php://input');
        if (trim($txt) === '') {
            return [];
        }
        if (strlen($txt) > 8 * 1024 * 1024) {
            throw new Erro('corpo grande demais', 413);
        }
        $b = json_decode($txt, true);
        if (!is_array($b)) {
            throw new Erro('corpo JSON inválido', 400);
        }
        return $b;
    }
}
