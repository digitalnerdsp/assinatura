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

    // Alertas de vencimento do certificado (dias).
    'expiry_alert_days' => [30, 15, 7],
];
