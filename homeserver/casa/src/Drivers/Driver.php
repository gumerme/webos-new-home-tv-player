<?php
declare(strict_types=1);

namespace Casa\Drivers;

interface Driver
{
    /**
     * Executa uma ação no aparelho.
     * @return array dados para mesclar no estado (ex.: ['ligado' => true])
     */
    public function executar(array $dispositivo, string $acao, array $parametros): array;

    /**
     * Lê o estado atual.
     * @return array{online: bool, dados: array}
     */
    public function consultar(array $dispositivo): array;

    /** Segundos entre consultas de estado. */
    public function intervalo(array $dispositivo): int;
}
