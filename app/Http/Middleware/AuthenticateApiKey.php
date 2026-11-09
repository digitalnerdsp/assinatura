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

        $key->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('tenant', $key->tenant);

        return $next($request);
    }
}
