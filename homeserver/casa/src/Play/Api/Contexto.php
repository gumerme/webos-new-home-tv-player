<?php
declare(strict_types=1);

namespace Casa\Play\Api;

/** Quem está chamando: uma TV (token próprio), o painel (sessão) ou uma integração (CASA_TOKEN). */
final class Contexto
{
    public function __construct(
        public readonly ?array $tv = null,
        public readonly ?array $usuario = null,
        public readonly bool $integracao = false,
    ) {
    }

    public function tvId(): ?int
    {
        return $this->tv ? (int) $this->tv['id'] : null;
    }

    public function tvNome(): ?string
    {
        return $this->tv['nome'] ?? null;
    }

    public function ehAdmin(): bool
    {
        return $this->integracao || ($this->usuario['perfil'] ?? '') === 'admin';
    }
}
