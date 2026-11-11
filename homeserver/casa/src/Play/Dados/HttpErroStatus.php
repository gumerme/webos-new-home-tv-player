<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

use RuntimeException;

/** HTTP respondeu fora de 2xx (status e cabeçalhos ficam disponíveis, ex.: Retry-After do TMDB). */
final class HttpErroStatus extends RuntimeException
{
    public function __construct(public readonly int $status, string $mensagem, public readonly array $cab = [])
    {
        parent::__construct($mensagem, $status);
    }
}
