<?php

declare(strict_types=1);

namespace App\Assistant\Channel;

use RuntimeException;
use Throwable;

/** Falha ao falar com a API da Meta. `status` é o HTTP (0 = rede) e `metaCode` o código de erro da Meta, quando houver. */
final class MetaException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly ?int $metaCode = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
