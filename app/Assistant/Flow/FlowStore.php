<?php

declare(strict_types=1);

namespace App\Assistant\Flow;

use App\Models\User;
use App\Models\WhatsAppFlow;

/** Estado da conversa guiada: um fluxo por usuário; cada passo renova os 30 minutos. */
final class FlowStore
{
    public const TTL_MINUTES = 30;

    public function current(User $user): ?WhatsAppFlow
    {
        $flow = WhatsAppFlow::query()->where('user_id', $user->getKey())->first();
        if ($flow === null) {
            return null;
        }
        if ($flow->expires_at->isPast()) {
            $flow->delete();

            return null;
        }

        return $flow;
    }

    /** @param array<string,mixed> $data */
    public function open(User $user, string $step, array $data): WhatsAppFlow
    {
        return WhatsAppFlow::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            ['tenant_id' => $user->tenant_id, 'step' => $step, 'data' => $data, 'expires_at' => now()->addMinutes(self::TTL_MINUTES)],
        );
    }

    /** @param array<string,mixed> $merge */
    public function advance(WhatsAppFlow $flow, string $step, array $merge = []): WhatsAppFlow
    {
        $flow->forceFill(['step' => $step, 'data' => array_replace((array) $flow->data, $merge), 'expires_at' => now()->addMinutes(self::TTL_MINUTES)])->save();

        return $flow;
    }

    public function clear(User $user): void
    {
        WhatsAppFlow::query()->where('user_id', $user->getKey())->delete();
    }
}
