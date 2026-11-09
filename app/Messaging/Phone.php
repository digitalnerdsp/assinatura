<?php

declare(strict_types=1);

namespace App\Messaging;

final class Phone
{
    /** Converte um telefone digitado livremente para E.164; null se não parecer um telefone válido. */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $hasPlus = str_starts_with(trim($raw), '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($hasPlus) {
            return strlen($digits) >= 10 && strlen($digits) <= 15 ? '+'.$digits : null;
        }

        $digits = ltrim($digits, '0');
        if (strlen($digits) === 10 || strlen($digits) === 11) { // DDD + número
            return '+'.config('messaging.default_country_code').$digits;
        }

        return strlen($digits) >= 12 && strlen($digits) <= 15 ? '+'.$digits : null;
    }
}
