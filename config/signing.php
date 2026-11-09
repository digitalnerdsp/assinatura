<?php

declare(strict_types=1);

return [
    // local_test | platform_a1 | platform_hsm
    'platform_provider' => env('SIGNING_PLATFORM_PROVIDER', 'local_test'),

    // Certificado autoassinado: só dev/staging. Nunca habilitar em produção.
    'allow_local_test' => (bool) env('SIGNING_ALLOW_LOCAL_TEST', env('APP_ENV') !== 'production'),

    'platform_a1_secret_ref' => env('SIGNING_PLATFORM_A1_SECRET_REF'),
    'platform_hsm_key_id' => env('SIGNING_PLATFORM_HSM_KEY_ID'),

    'sealer' => [
        'url' => env('SEALER_URL', 'http://sealer:8080'),
        'token' => env('SEALER_TOKEN'),
        'timeout' => (int) env('SEALER_TIMEOUT', 60),
    ],

    // Tamanho máximo do PDF enviado (MB). O sealer e o PHP precisam suportar esse valor.
    'max_pdf_mb' => (int) env('SIGNING_MAX_PDF_MB', 20),

    // Validade padrão do envelope e intervalo padrão de lembretes (dias).
    'default_validity_days' => (int) env('SIGNING_VALIDITY_DAYS', 30),
    'default_reminder_days' => (int) env('SIGNING_REMINDER_DAYS', 3),
    'max_reminders' => 5,

    // Alertas de vencimento do certificado (dias).
    'expiry_alert_days' => [30, 15, 7],
];
