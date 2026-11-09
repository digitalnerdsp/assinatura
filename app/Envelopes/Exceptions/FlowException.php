<?php

declare(strict_types=1);

namespace App\Envelopes\Exceptions;

use RuntimeException;

/** Violação de regra do fluxo de assinatura (ordem, OTP, expiração...). */
final class FlowException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
