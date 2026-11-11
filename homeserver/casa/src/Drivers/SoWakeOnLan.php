<?php
declare(strict_types=1);

namespace Casa\Drivers;

use Casa\Net\WakeOnLan;

/** Aparelho que só liga por WoL (ex.: PC). Estado = responde ping TCP numa porta. */
final class SoWakeOnLan implements Driver
{
    public function executar(array $d, string $acao, array $p): array
    {
        if ($acao !== 'ligar') {
            throw new \InvalidArgumentException("ação '$acao' não suportada (só ligar)");
        }
        WakeOnLan::enviar((string) ($d['config']['mac'] ?? ''), $d['config']['broadcast'] ?? '255.255.255.255');
        return ['ligado' => true];
    }

    public function consultar(array $d): array
    {
        $ip = $d['config']['ip'] ?? null;
        $porta = (int) ($d['config']['porta_teste'] ?? 0);
        if (!$ip || !$porta) {
            return ['online' => false, 'dados' => []];
        }
        $s = @fsockopen($ip, $porta, $e, $m, 1.0);
        if ($s) {
            fclose($s);
        }
        return ['online' => (bool) $s, 'dados' => ['ligado' => (bool) $s]];
    }

    public function intervalo(array $d): int
    {
        return 30;
    }
}
