<?php
declare(strict_types=1);

namespace Casa\Net;

final class WakeOnLan
{
    /** Envia o "magic packet" (3x, para a TV pegar mesmo saindo do standby profundo). */
    public static function enviar(string $mac, string $broadcast = '255.255.255.255', int $porta = 9): void
    {
        $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', $mac) ?? '');
        if (strlen($hex) !== 12) {
            throw new \InvalidArgumentException("MAC inválido: '$mac'");
        }
        $pacote = str_repeat("\xFF", 6) . str_repeat((string) hex2bin($hex), 16);

        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            throw new \RuntimeException('socket UDP: ' . socket_strerror(socket_last_error()));
        }
        try {
            socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
            $destinos = array_unique([$broadcast, '255.255.255.255']);
            for ($i = 0; $i < 3; $i++) {
                foreach ($destinos as $destino) {
                    if (socket_sendto($sock, $pacote, strlen($pacote), 0, $destino, $porta) === false) {
                        throw new \RuntimeException("WoL para $destino: " . socket_strerror(socket_last_error($sock)));
                    }
                }
                usleep(100_000);
            }
        } finally {
            socket_close($sock);
        }
    }
}
