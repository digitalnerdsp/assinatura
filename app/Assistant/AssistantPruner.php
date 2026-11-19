<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Models\AssistantConversation;
use App\Models\WhatsAppInbound;
use App\Models\WhatsAppLinkAttempt;
use App\Models\WhatsAppLinkCode;
use App\Models\WhatsAppStatusEvent;

/**
 * Limpeza de dados de conversa: conversas e mensagens recebidas com mais de 90 dias, códigos de vínculo vencidos e tentativas
 * antigas. Lançamentos de tokens e uso da Meta NÃO são apagados: são registro financeiro.
 */
final class AssistantPruner
{
    public const KEEP_DAYS = 90;

    /** @return array{conversations:int,inbounds:int,codes:int,attempts:int,status_events:int} */
    public function run(): array
    {
        $cut = now()->subDays(self::KEEP_DAYS);

        return [
            'conversations' => AssistantConversation::query()->where('last_message_at', '<', $cut)->delete(), // as mensagens caem por cascata
            'inbounds' => WhatsAppInbound::query()->where('created_at', '<', $cut)->delete(),
            'codes' => WhatsAppLinkCode::query()->where(fn ($q) => $q->where('expires_at', '<', now()->subDay())->orWhereNotNull('used_at'))->delete(),
            'attempts' => WhatsAppLinkAttempt::query()->where('updated_at', '<', now()->subDays(7))->where(fn ($q) => $q->whereNull('blocked_until')->orWhere('blocked_until', '<', now()))->delete(),
            'status_events' => WhatsAppStatusEvent::query()->whereNotNull('processed_at')->where('processed_at', '<', $cut)->delete(),
        ];
    }
}
