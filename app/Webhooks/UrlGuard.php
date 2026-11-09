<?php

declare(strict_types=1);

namespace App\Webhooks;

/** Impede que webhooks sejam usados para atingir a rede interna (SSRF). */
final class UrlGuard
{
    /** Retorna a mensagem de erro, ou null se a URL é aceitável. */
    public static function problem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Informe uma URL http(s) válida.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'A URL não pode conter usuário e senha.';
        }

        if (config('webhooks.allow_private')) {
            return null;
        }

        if ($scheme !== 'https') {
            return 'Use uma URL https.';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return 'Não foi possível resolver o endereço da URL.';
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'A URL aponta para um endereço interno, não permitido.';
            }
        }

        return null;
    }
}
