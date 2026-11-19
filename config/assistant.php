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

    // API de conversa da OpenAI (chamada de funções) e limites por conversa.
    'openai_url' => env('ASSISTANT_OPENAI_URL', 'https://api.openai.com/v1'),
    'max_output_tokens' => (int) env('ASSISTANT_MAX_OUTPUT_TOKENS', 700),
    'history_messages' => 12,
    'history_chars' => 6000,
    // Prazo total para responder uma mensagem (segundos): menor que o tempo limite do job da fila.
    'max_seconds' => (int) env('ASSISTANT_MAX_SECONDS', 65),
    'messages_per_hour' => (int) env('ASSISTANT_MESSAGES_PER_HOUR', 40),

    // Maior PDF assinado que o assistente envia pelo WhatsApp (MB); acima disso responde com o link do painel.
    'max_send_mb' => (float) env('ASSISTANT_MAX_SEND_MB', 16),

    // Validade (meses) dos pacotes extras comprados.
    'purchase_validity_months' => (int) env('ASSISTANT_PURCHASE_VALIDITY_MONTHS', 12),
];
