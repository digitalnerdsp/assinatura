<?php

declare(strict_types=1);

namespace App\Assistant\Channel;

use App\Assistant\AssistantSettings;
use App\Assistant\Metering\MetaUsageLedger;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppStatusEvent;
use Carbon\CarbonImmutable;

/**
 * Trata o aviso de status que traz preço (`pricing`). A Meta informa categoria e se cobra; o valor vem da tabela da plataforma.
 * Se o número ainda não tem empresa ou a categoria não tem preço cadastrado, fica guardado para conciliar: nunca cobra no chute.
 */
final class StatusProcessor
{
    public function __construct(private readonly AssistantSettings $settings, private readonly MetaUsageLedger $ledger)
    {
    }

    /** @param array<string,mixed> $status um item de `statuses[]` */
    public function handle(array $status): void
    {
        $id = (string) ($status['id'] ?? '');
        $pricing = $status['pricing'] ?? null;
        if ($id === '' || ! is_array($pricing)) {
            return;
        }

        $event = [
            'recipient_phone' => preg_replace('/\D/', '', (string) ($status['recipient_id'] ?? '')) ?? '',
            'status' => isset($status['status']) ? mb_substr((string) $status['status'], 0, 16) : null,
            'category' => mb_substr((string) ($pricing['category'] ?? 'unknown'), 0, 32),
            'billable' => (bool) ($pricing['billable'] ?? false),
            'occurred_at' => CarbonImmutable::createFromTimestampUTC((int) ($status['timestamp'] ?? time())),
        ];

        if (! $this->charge($id, $event)) {
            WhatsAppStatusEvent::query()->firstOrCreate(['wa_message_id' => $id], $event + ['tenant_id' => $this->tenantFor($event['recipient_phone'])?->getKey()]);
        }
    }

    /** Cobra os avisos guardados que agora têm empresa e preço. @return int quantos foram resolvidos */
    public function reconcile(): int
    {
        $done = 0;
        WhatsAppStatusEvent::query()->whereNull('processed_at')->orderBy('occurred_at')->each(function (WhatsAppStatusEvent $e) use (&$done): void {
            $ok = $this->charge($e->wa_message_id, [
                'recipient_phone' => $e->recipient_phone, 'category' => (string) $e->category, 'billable' => $e->billable, 'occurred_at' => $e->occurred_at,
            ]);
            if ($ok) {
                $e->forceFill(['processed_at' => now()])->save();
                $done++;
            }
        });

        return $done;
    }

    /** @param array<string,mixed> $e @return bool registrou o uso? */
    private function charge(string $id, array $e): bool
    {
        $tenant = $this->tenantFor((string) $e['recipient_phone']);
        if ($tenant === null) {
            return false;
        }
        $amount = $e['billable'] ? $this->settings->rateFor((string) $e['category']) : 0.0;
        if ($amount === null) {
            return false;
        }

        $this->ledger->record($tenant, $id, (string) $e['category'], (bool) $e['billable'], $amount, $this->settings->currency(), CarbonImmutable::instance($e['occurred_at']));

        return true;
    }

    private function tenantFor(string $phone): ?Tenant
    {
        if ($phone === '') {
            return null;
        }
        $user = User::query()->where('whatsapp_phone', $phone)->first();

        return $user?->tenant;
    }
}
