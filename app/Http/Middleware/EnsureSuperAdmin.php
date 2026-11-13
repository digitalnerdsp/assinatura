<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Áreas só de super admin (equipe, e-mail, configurações). Quem não é da plataforma recebe 404 (não revela
 * que a rota existe); o admin comum, que já conhece o painel, recebe 403.
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->isPlatformAdmin(), 404);
        abort_unless($user->isSuperAdmin(), 403, 'Esta área é só para super administradores.');

        return $next($request);
    }
}
