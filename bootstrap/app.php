<?php

use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.key' => AuthenticateApiKey::class,
            'super.admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'platform' => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'owner' => \App\Http\Middleware\EnsureOwner::class,
            'feature' => \App\Http\Middleware\EnsureFeature::class,
            'tenant.active' => \App\Http\Middleware\EnsureTenantActive::class,
            'idempotent' => \App\Http\Middleware\Idempotent::class,
        ]);

        // Atrás do proxy do Coolify (Traefik): sem isto request->ip() devolve o IP do proxy
        // e a trilha de evidências gravaria o IP errado. Não exponha o contêiner direto à internet.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        \Sentry\Laravel\Integration::handles($exceptions);

        // Erros da API em um formato só: {"error": "codigo", "message": "...", "errors": {...}}.
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json(['error' => 'validation_failed', 'message' => 'Dados inválidos.', 'errors' => $e->errors()], 422)
                : null;
        });
        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            return $request->is('api/*') ? response()->json(['error' => 'not_found', 'message' => 'Recurso não encontrado.'], 404) : null;
        });
        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json(['error' => 'rate_limited', 'message' => 'Muitas requisições. Aguarde e tente de novo.'], 429, $e->getHeaders())
                : null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
