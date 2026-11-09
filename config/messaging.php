<?php

return [
    // log | twilio. Com "log" as mensagens só vão para o log (útil em desenvolvimento).
    'driver' => env('MESSAGING_DRIVER', 'log'),

    // Código do país aplicado a telefones sem DDI (Brasil).
    'default_country_code' => env('MESSAGING_DEFAULT_COUNTRY', '55'),

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from_sms' => env('TWILIO_FROM_SMS'),           // número ou Messaging Service SID (MG...)
        'from_whatsapp' => env('TWILIO_FROM_WHATSAPP'), // ex.: +14155238886 (sandbox) ou número aprovado
    ],
];
