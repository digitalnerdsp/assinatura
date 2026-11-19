<?php

declare(strict_types=1);

namespace App\Assistant\Flow;

/** Lê o que a pessoa escreveu para cada signatário: nome e e-mail em qualquer ordem ("Maria Souza maria@x.com"). */
final class SignerLine
{
    public const MAX_NAME = 150;

    /**
     * @return array{name:string,email:?string}|null null = nada aproveitável, mais de um e-mail ou nome grande demais
     */
    public static function parse(string $text): ?array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return null;
        }

        // E-mail com letra acentuada colada no @ não é aceito (seria cortado em silêncio): a pessoa precisa digitar de novo.
        if (preg_match('/[^\s@]*[^\x00-\x7F][^\s@]*@|@\S*[^\x00-\x7F]/u', $text) === 1) {
            return null;
        }

        preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+/', $text, $m);
        if (count($m[0]) > 1) {
            return null; // uma pessoa por mensagem
        }

        $email = null;
        $rest = $text;
        if ($m[0] !== []) {
            $candidate = mb_strtolower($m[0][0]);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $email = $candidate;
                $rest = str_replace($m[0][0], ' ', $text);
            }
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace("/[^\\p{L}\\p{N} .'\\-]/u", ' ', $rest)), " .-'");
        if ($name === '') {
            if ($email === null) {
                return null;
            }
            $name = ucwords(trim((string) preg_replace('/[._+\-]+/', ' ', explode('@', $email)[0])));
            if ($name === '') {
                return null;
            }
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            return null;
        }

        return ['name' => $name, 'email' => $email];
    }
}
