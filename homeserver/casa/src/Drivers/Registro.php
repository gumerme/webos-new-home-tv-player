<?php
declare(strict_types=1);

namespace Casa\Drivers;

final class Registro
{
    /** driver (coluna dispositivo.driver) => classe */
    private const DRIVERS = [
        'lgwebos' => LgWebOs::class,
        'wol'     => SoWakeOnLan::class,
        // 'matter' => Matter::class,   // via matter-server (ws://127.0.0.1:5580/ws)
        // 'midea'  => Midea::class,    // via container msmart (HTTP)
    ];

    /** @var array<string, Driver> */
    private static array $cache = [];

    public static function driver(string $nome): Driver
    {
        $classe = self::DRIVERS[$nome] ?? throw new \InvalidArgumentException("driver desconhecido: $nome");
        return self::$cache[$nome] ??= new $classe();
    }

    public static function nomes(): array
    {
        return array_keys(self::DRIVERS);
    }
}
