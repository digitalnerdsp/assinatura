<?php

declare(strict_types=1);

namespace App\Support;

/** Leitura de valores em reais digitados por pessoas ("20", "20,50", "1.250,50", "1.000" = mil). */
final class Money
{
    public static function reais(string $value): ?float
    {
        $v = trim(str_replace(['R$', ' '], '', $value));
        if (str_contains($v, ',')) {
            $v = str_replace(['.', ','], ['', '.'], $v); // 1.250,50
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $v) === 1) {
            $v = str_replace('.', '', $v); // 1.000 = mil
        }

        return is_numeric($v) ? round((float) $v, 6) : null;
    }
}
