<?php
declare(strict_types=1);

namespace Casa\Play\Api;

/** Erro da API do Player: código HTTP + campos extras no JSON (ex.: "codigo" da TV pendente). */
final class Erro extends \RuntimeException
{
    public function __construct(string $mensagem, int $status = 400, public readonly array $extra = [])
    {
        parent::__construct($mensagem, $status);
    }
}
