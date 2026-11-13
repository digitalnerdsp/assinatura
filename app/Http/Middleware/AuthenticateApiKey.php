<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Autentica chamadas da API por "Authorization: Bearer sk_live_..." e injeta o tenant. */
final class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        $key = $token
            ? ApiKey::query()->with('tenant')->where('key_hash', hash('sha256', $token))->whereNull('revoked_at')->first()
            : null;

        if ($key === null) {
            return response()->json(['error' => 'unauthorized', 'message' => 'Chave de API inválida.'], 401);
        }

        if ($key->tenant->isSuspended()) {
            return response()->json(['error' => 'tenant_suspended', 'message' => 'Conta suspensa.'], 403);
        }

        if (! \App\Plans\Entitlements::for($key->tenant)->feature('api')) {
            return response()->json(['error' => 'feature_off', 'message' => 'Seu plano não inclui o acesso pela API. Fale com o suporte ou troque de plano.'], 403);
        }

        // Chave somente leitura: só consulta (GET/HEAD).
        if ($key->read_only && ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return response()->json(['error' => 'read_only_key', 'message' => 'Esta chave é somente leitura.'], 403);
        }

        $key->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('tenant', $key->tenant);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
