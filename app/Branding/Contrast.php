<?php

declare(strict_types=1);

namespace App\Branding;

/** Contraste de cores (WCAG). Regra do projeto: texto com no mínimo 4,5:1. */
final class Contrast
{
    public const MIN = 4.5;

    /** "#ABC" ou "#aabbcc" → "#aabbcc". */
    public static function normalize(string $hex): string
    {
        $h = strtolower(ltrim(trim($hex), '#'));
        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }

        return '#'.$h;
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $f = static function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Mistura `$hex` com `$with` na proporção `$t` (0 = $hex, 1 = $with). */
    public static function mix(string $hex, string $with, float $t): string
    {
        $a = self::rgb($hex);
        $b = self::rgb($with);
        $out = array_map(fn ($x, $y) => (int) round($x + ($y - $x) * $t), $a, $b);

        return sprintf('#%02x%02x%02x', ...$out);
    }

    /** O próprio tom, se já passa; senão o tom mais próximo (clareando ou escurecendo) que passa contra `$bg`. */
    public static function nearestAccessible(string $hex, string $bg = '#ffffff', float $min = self::MIN): string
    {
        $hex = self::normalize($hex);
        if (self::ratio($hex, $bg) >= $min) {
            return $hex;
        }
        $target = self::luminance($bg) > 0.5 ? '#000000' : '#ffffff';
        for ($t = 0.02; $t <= 1.0; $t += 0.02) {
            $candidate = self::mix($hex, $target, $t);
            if (self::ratio($candidate, $bg) >= $min) {
                return $candidate;
            }
        }

        return $target;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim(self::normalize($hex), '#');

        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }
}
