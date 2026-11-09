<?php

declare(strict_types=1);

namespace App\Messaging;

use Illuminate\Support\Facades\Log;

/** Não envia nada: só registra. Padrão até configurar um provedor real. */
final class LogSender implements MessageSender
{
    public function send(string $channel, string $to, string $text): void
    {
        Log::info("[mensagem:{$channel}] para {$to}: {$text}");
    }
}
