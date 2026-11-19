<?php

declare(strict_types=1);

/** Padrões do assistente no WhatsApp. O que o super administrador salva na tela da plataforma vence estes valores. */
return [
    // Modelo da OpenAI (Luna). O identificador exato precisa ser confirmado na conta da OpenAI antes do piloto.
    'model' => env('ASSISTANT_MODEL', 'gpt-5.6-luna'),

    // Pacote de tokens equivalentes que o plano inclui por mês quando o plano não define o próprio.
    'default_package_tokens' => (int) env('ASSISTANT_DEFAULT_PACKAGE_TOKENS', 50000),

    // Quanto o saldo pode passar de zero para uma conversa em andamento terminar (a plataforma absorve).
    'overrun_cap' => (int) env('ASSISTANT_OVERRUN_CAP', 20000),

    // Acréscimo (%) sobre o valor das mensagens da Meta repassado ao cliente.
    'markup_percent' => (float) env('ASSISTANT_MARKUP_PERCENT', 0),

    // Tokens equivalentes: saída pesa mais que entrada e a entrada em cache pesa menos (ajustar pela tabela de preços real).
    'output_factor' => (float) env('ASSISTANT_OUTPUT_FACTOR', 4),
    'cached_factor' => (float) env('ASSISTANT_CACHED_FACTOR', 0.1),

    // Validade (meses) dos pacotes extras comprados.
    'purchase_validity_months' => (int) env('ASSISTANT_PURCHASE_VALIDITY_MONTHS', 12),
];
