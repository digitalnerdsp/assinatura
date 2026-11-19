<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Assistant\Channel\InboundHandler;
use App\Assistant\Channel\InboundMessage;
use App\Models\WhatsAppInbound;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Trata uma mensagem recebida fora da requisição do webhook. Exatamente uma vez: a linha é marcada como "começou" de forma
 * atômica antes de qualquer efeito (IA, ferramentas, envio), e a fila nunca repete o job (repetir cobraria a IA de novo e
 * mandaria as respostas em dobro). O tempo limite é menor que o `retry_after` da fila para outro worker não pegar o mesmo job.
 */
final class ProcessInboundWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 80;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $inboundId)
    {
    }

    /** Mensagens seguidas do mesmo número entram uma de cada vez (histórico e respostas na ordem). */
    public function middleware(): array
    {
        $phone = WhatsAppInbound::query()->whereKey($this->inboundId)->value('from_phone');

        return [(new WithoutOverlapping('assistant-chat:'.($phone ?? $this->inboundId)))->releaseAfter(15)->expireAfter(150)];
    }

    public function handle(InboundHandler $handler): void
    {
        $claimed = WhatsAppInbound::query()->whereKey($this->inboundId)->whereNull('processed_at')->whereNull('processing_started_at')
            ->update(['processing_started_at' => now()]);
        if ($claimed === 0) {
            return; // já tratada, ou em tratamento por outro worker
        }

        $row = WhatsAppInbound::query()->findOrFail($this->inboundId);
        $handler->handle(InboundMessage::fromRow($row));
        $row->forceFill(['processed_at' => now()])->save();
    }
}
