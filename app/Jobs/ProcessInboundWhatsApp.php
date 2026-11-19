<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Assistant\Channel\InboundHandler;
use App\Assistant\Channel\InboundMessage;
use App\Models\WhatsAppInbound;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Trata uma mensagem recebida fora da requisição do webhook (a Meta exige resposta rápida). */
final class ProcessInboundWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function __construct(public readonly string $inboundId)
    {
    }

    public function handle(InboundHandler $handler): void
    {
        $row = WhatsAppInbound::query()->find($this->inboundId);
        if ($row === null || $row->processed_at !== null) {
            return; // idempotente
        }

        $handler->handle(InboundMessage::fromRow($row));
        $row->forceFill(['processed_at' => now()])->save();
    }
}
