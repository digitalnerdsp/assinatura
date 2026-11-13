<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Plans\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Barra o acesso a um recurso que o plano da empresa não inclui (uso: `feature:templates`). */
final class EnsureFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = $request->attributes->get('tenant') ?? $request->user()?->tenant;

        if ($tenant !== null && ! Entitlements::for($tenant)->feature($feature)) {
            $message = 'Seu plano não inclui este recurso. Fale com o suporte ou troque de plano.';
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['error' => 'feature_off', 'message' => $message], 403);
            }
            abort(403, $message);
        }

        return $next($request);
    }
}
