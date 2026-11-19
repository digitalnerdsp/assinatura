<?php

declare(strict_types=1);

namespace App\Assistant\Llm;

use RuntimeException;
use Throwable;

/** A IA não respondeu (chave ausente, rede, limite, erro do servidor). O assistente avisa e oferece o menu fixo. */
final class LlmUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
