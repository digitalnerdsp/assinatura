<?php

declare(strict_types=1);

namespace App\Assistant\Metering;

use App\Assistant\AssistantSettings;
use App\Models\MetaMessageUsage;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/** Registro, por empresa, das mensagens do WhatsApp que a Meta informou (categoria e preço), com o acréscimo do repasse. */
final class MetaUsageLedger
{
    private const TZ = 'America/Sao_Paulo';

    public function __construct(private readonly AssistantSettings $settings)
    {
    }

    /** Idempotente por id da mensagem: a mesma notificação da Meta repetida não cobra de novo. */
    public function record(Tenant $tenant, string $waMessageId, string $category, bool $billable, float $amount, string $currency, CarbonInterface $at): void
    {
        $markup = $this->settings->markupPercent();
        $now = now();

        $id = (string) Str::uuid();
        $inserted = MetaMessageUsage::query()->insertOrIgnore([[
            'id' => $id,
            'tenant_id' => $tenant->getKey(),
            'wa_message_id' => $waMessageId,
            'category' => mb_substr($category, 0, 32),
            'billable' => $billable,
            'meta_amount' => $billable ? $amount : 0,
            'currency' => strtoupper(mb_substr($currency, 0, 3)),
            'charged_amount' => $billable ? round($amount * (1 + $markup / 100), 6) : 0,
            'markup_percent' => $markup,
            'period' => $at->copy()->timezone(self::TZ)->format('Y-m'),
            'occurred_at' => $at,
            'created_at' => $now,
            'updated_at' => $now,
        ]]);

        // Um débito por mensagem. Mesmo quando o uso já existia (aviso repetido), tenta debitar: se uma queda anterior
        // gravou o uso sem o débito, ele é refeito aqui (o índice único de `source_id` impede o dobro).
        if ($billable && ($usage = MetaMessageUsage::query()->where('wa_message_id', $waMessageId)->first()) !== null) {
            app(WhatsAppCredit::class)->debitUsage($tenant, $usage);
        }
    }

    /** @return array{meta:float,charged:float,count:int} */
    public function monthTotal(Tenant $tenant, string $period): array
    {
        $q = MetaMessageUsage::query()->where('tenant_id', $tenant->getKey())->where('period', $period);

        return [
            'meta' => (float) (clone $q)->sum('meta_amount'),
            'charged' => (float) (clone $q)->sum('charged_amount'),
            'count' => (clone $q)->count(),
        ];
    }
}
