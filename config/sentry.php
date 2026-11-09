<?php

// Alertas de erro (Sentry). Sem SENTRY_LARAVEL_DSN no ambiente, nada é enviado.
// Os demais valores seguem o padrão do pacote (vendor/sentry/sentry-laravel/config/sentry.php).
return [
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),
    'release' => env('SENTRY_RELEASE'),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    // Privacidade: documentos, selfies, CPFs e tokens de assinatura nunca devem sair do servidor.
    'send_default_pii' => false,
    'max_request_body_size' => 'none',
    'before_send' => [\App\Support\SentryScrubber::class, 'beforeSend'],
    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => false,
        'notifications' => false,
    ],
];
