<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Só o responsável da empresa (chaves de API e webhooks: credenciais que não pertencem a uma pessoa). */
final class EnsureOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->role === 'owner', 403, 'Só o responsável da empresa pode acessar esta área.');

        return $next($request);
    }
}
