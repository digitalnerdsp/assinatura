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

        $tenant = $this->tenantFor($id, $event['recipient_phone']);
        if ($tenant === null || ! $this->charge($tenant, $id, $event)) {
            // Sem empresa na hora: era mensagem da própria plataforma (orientação, vínculo) e nunca é cobrada de quem vincula depois.
            // Com empresa mas sem preço cadastrado: espera o preço (assistant:reconcile).
            WhatsAppStatusEvent::query()->firstOrCreate(['wa_message_id' => $id], $event + ['tenant_id' => $tenant?->getKey()]);
        }
    }

    /** Cobra os avisos guardados que têm empresa (a gravada no evento) e agora têm preço. @return int quantos foram resolvidos */
    public function reconcile(): int
    {
        $done = 0;
        WhatsAppStatusEvent::query()->whereNull('processed_at')->whereNotNull('tenant_id')->orderBy('occurred_at')->each(function (WhatsAppStatusEvent $e) use (&$done): void {
            $tenant = Tenant::query()->find($e->tenant_id);
            if ($tenant !== null && $this->charge($tenant, $e->wa_message_id, [
                'category' => (string) $e->category, 'billable' => $e->billable, 'occurred_at' => $e->occurred_at,
            ])) {
                $e->forceFill(['processed_at' => now()])->save();
                $done++;
            }
        });

        return $done;
    }

    /** @param array<string,mixed> $e @return bool registrou o uso? */
    private function charge(Tenant $tenant, string $id, array $e): bool
    {
        $amount = $e['billable'] ? $this->settings->rateFor((string) $e['category']) : 0.0;
        if ($amount === null) {
            return false;
        }

        $this->ledger->record($tenant, $id, (string) $e['category'], (bool) $e['billable'], $amount, $this->settings->currency(), CarbonImmutable::instance($e['occurred_at']));

        return true;
    }

    /** Aviso que nós enviamos: a empresa gravada no envio paga, mesmo que o número tenha mudado de dono. Senão, quem tem o número agora. */
    private function tenantFor(string $waMessageId, string $phone): ?Tenant
    {
        $sent = \App\Models\WhatsAppNotification::query()->where('wa_message_id', $waMessageId)->first();
        if ($sent !== null) {
            return Tenant::query()->find($sent->tenant_id);
        }
        if ($phone === '') {
            return null;
        }
        $user = User::query()->where('whatsapp_phone', $phone)->first();

        return $user?->tenant;
    }
}
