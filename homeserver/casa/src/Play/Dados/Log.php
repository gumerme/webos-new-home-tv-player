<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

/** Linha no log do container (docker logs casa-play), sempre sem segredos. */
final class Log
{
    public static bool $silencioso = false;

    public static function info(string $msg): void
    {
        if (self::$silencioso) {
            return;
        }
        if (defined('STDOUT')) {
            fwrite(STDOUT, date('Y-m-d H:i:s') . ' ' . Http::mascarar($msg) . "\n");
        } else {
            error_log('casa-play: ' . Http::mascarar($msg));      // dentro do Apache (a API chamando Legendas/Epg)
        }
    }
}
