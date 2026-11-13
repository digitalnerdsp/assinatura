<?php

declare(strict_types=1);

namespace App\Plans;

use App\Envelopes\Exceptions\FlowException;

/** Resposta de "pode fazer isto agora?": liberado, ou o motivo (código + mensagem em português). */
final class Decision
{
    /** Códigos que significam "o plano não permite" (a API responde 403 plan_limit_reached). */
    public const PLAN_REASONS = ['documents_limit', 'users_limit', 'storage_limit', 'pdf_too_large', 'feature_off', 'blocked'];

    private function __construct(
        public readonly bool $allowed,
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, 'ok', '');
    }

    public static function deny(string $code, string $message): self
    {
        return new self(false, $code, $message);
    }

    public static function isPlanReason(string $code): bool
    {
        return in_array($code, self::PLAN_REASONS, true);
    }

    /** @throws FlowException quando não está liberado */
    public function orFail(): void
    {
        if (! $this->allowed) {
            throw FlowException::because($this->code, $this->message);
        }
    }
}
