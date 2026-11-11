<?php
declare(strict_types=1);

namespace Casa\Play\Api;

/** Uma chamada à API: parâmetros da rota, da query e do corpo JSON, mais quem chamou. */
final class Pedido
{
    /** Código HTTP da resposta (o handler pode trocar, ex.: 202). */
    public int $status = 200;
    private ?int $perfilId = null;

    public function __construct(
        public readonly string $metodo,
        public readonly array $rota,
        public readonly array $query,
        public readonly array $corpo,
        public readonly Contexto $ctx,
    ) {
    }

    /** Valor da query ou do corpo (nessa ordem). */
    public function valor(string $k): mixed
    {
        return $this->query[$k] ?? $this->corpo[$k] ?? null;
    }

    public function int(string $k, ?int $padrao = null): ?int
    {
        $v = $this->valor($k);
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^-?\d{1,12}$/', trim($v))) {
            return (int) $v;
        }
        if (is_float($v) && is_finite($v)) {
            return (int) $v;
        }
        return $padrao;
    }

    public function str(string $k, string $padrao = ''): string
    {
        $v = $this->valor($k);
        return is_scalar($v) ? trim((string) $v) : $padrao;
    }

    public function bool(string $k): bool
    {
        $v = $this->valor($k);
        return $v === true || $v === 1 || in_array(is_string($v) ? strtolower($v) : '', ['1', 'true', 'sim', 'on'], true);
    }

    /** Parâmetro inteiro da rota ({id}, {tmdb}, {canal}). */
    public function id(string $k = 'id'): int
    {
        return (int) ($this->rota[$k] ?? 0);
    }

    /**
     * Perfil da chamada: o pedido explícito, senão o perfil padrão da TV, senão o primeiro ("Casa").
     */
    public function perfil(): int
    {
        if ($this->perfilId !== null) {
            return $this->perfilId;
        }
        $pedido = $this->int('perfil');
        if ($pedido !== null && $pedido > 0 && Base::valor('select 1 from play.perfil where id = ?', [$pedido])) {
            return $this->perfilId = $pedido;
        }
        $tv = $this->ctx->tv['perfil_id'] ?? null;
        if ($tv && Base::valor('select 1 from play.perfil where id = ?', [(int) $tv])) {
            return $this->perfilId = (int) $tv;
        }
        $primeiro = Base::valor('select min(id) from play.perfil');
        if (!$primeiro) {
            throw new Erro('nenhum perfil cadastrado', 409);
        }
        return $this->perfilId = (int) $primeiro;
    }
}
