<?php

declare(strict_types=1);

namespace App\Assistant\Metering;

use App\Models\MetaMessageUsage;
use App\Models\Tenant;
use App\Models\WhatsAppCreditEntry;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Saldo em dinheiro das mensagens do WhatsApp. Pré-pago: só envia com saldo. Pós-pago: pode ficar negativo até o limite
 * e a dívida é quitada junto com a mensalidade. A empresa do super administrador nunca é debitada (a plataforma absorve).
 */
final class WhatsAppCredit
{
    public function balance(Tenant $tenant): float
    {
        return round((float) WhatsAppCreditEntry::query()->where('tenant_id', $tenant->getKey())->sum('amount'), 6);
    }

    /** Quanto ainda pode gastar: o saldo, mais o limite quando é pós-pago. */
    public function available(Tenant $tenant): float
    {
        $limit = $tenant->wa_billing_mode === 'postpaid' ? max(0.0, (float) $tenant->wa_credit_limit) : 0.0;

        return round(max(0.0, $this->balance($tenant) + $limit - $this->reserved($tenant)), 6);
    }

    /**
     * Custo estimado dos avisos já enfileirados ou enviados que a Meta ainda não cobrou. Conta por até 3 dias
     * (depois disso, sem aviso de cobrança, o aviso era gratuito ou se perdeu) e some quando o uso real chega.
     */
    public function reserved(Tenant $tenant): float
    {
        return round((float) \Illuminate\Support\Facades\DB::table('whatsapp_notifications as n')
            ->where('n.tenant_id', $tenant->getKey())->whereIn('n.status', ['queued', 'sending', 'sent'])->where('n.reserved_amount', '>', 0)
            ->where('n.created_at', '>', now()->subDays(3))
            ->whereNotExists(fn ($q) => $q->from('meta_message_usages as u')->whereColumn('u.wa_message_id', 'n.wa_message_id'))
            ->sum('n.reserved_amount'), 6);
    }

    public function canAfford(Tenant $tenant, float $amount): bool
    {
        if ($tenant->isPlatformOwned()) {
            return true;
        }

        return $this->available($tenant) + 1e-9 >= $amount;
    }

    public function topUp(Tenant $tenant, float $amount, string $note, ?string $by = null): void
    {
        $this->add($tenant, 'topup', $this->positive($amount), $note, $by);
    }

    /** Ajuste com sinal (+ ou −), sempre com nota. */
    public function adjust(Tenant $tenant, float $amount, string $note, ?string $by = null): void
    {
        if (abs($amount) < 0.000001) {
            throw new InvalidArgumentException('O valor do ajuste não pode ser zero.');
        }
        $this->add($tenant, 'adjust', round($amount, 6), $note, $by);
    }

    /** Quitação do pós-pago (cobrado na mensalidade): soma ao saldo. */
    public function settle(Tenant $tenant, float $amount, string $note, ?string $by = null): void
    {
        $this->add($tenant, 'settle', $this->positive($amount), $note, $by);
    }

    /** Débito da mensagem cobrada pela Meta, uma vez por mensagem. Não debita a empresa da plataforma. */
    public function debitUsage(Tenant $tenant, MetaMessageUsage $usage): void
    {
        $amount = (float) $usage->charged_amount;
        if (! $usage->billable || $amount <= 0 || $usage->tenant_id !== $tenant->getKey() || $tenant->isPlatformOwned()) {
            return;
        }

        WhatsAppCreditEntry::query()->insertOrIgnore([[
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $tenant->getKey(),
            'kind' => 'usage',
            'amount' => -$amount,
            'currency' => $usage->currency,
            'period' => $usage->period,
            'source_id' => $usage->getKey(),
            'note' => 'Mensagem '.$usage->category,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
    }

    /** @return array{topups:float,usage:float,messages:int} */
    public function monthSummary(Tenant $tenant, string $period): array
    {
        $q = WhatsAppCreditEntry::query()->where('tenant_id', $tenant->getKey());
        $start = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $period.'-01', 'America/Sao_Paulo')->startOfMonth();

        return [
            'topups' => round((float) (clone $q)->whereIn('kind', ['topup', 'settle'])->where('created_at', '>=', $start)->where('created_at', '<', $start->addMonth())->sum('amount'), 6),
            'usage' => round(abs((float) (clone $q)->where('kind', 'usage')->where('period', $period)->sum('amount')), 6),
            'messages' => (clone $q)->where('kind', 'usage')->where('period', $period)->count(),
        ];
    }

    /** @return Collection<int,WhatsAppCreditEntry> */
    public function entries(Tenant $tenant, int $limit = 100): Collection
    {
        return WhatsAppCreditEntry::query()->where('tenant_id', $tenant->getKey())->orderByDesc('created_at')->limit($limit)->get();
    }

    private function add(Tenant $tenant, string $kind, float $amount, string $note, ?string $by): void
    {
        WhatsAppCreditEntry::query()->create([
            'tenant_id' => $tenant->getKey(), 'kind' => $kind, 'amount' => $amount, 'currency' => 'BRL',
            'note' => mb_substr($note, 0, 190), 'created_by' => $by !== null ? mb_substr($by, 0, 190) : null,
        ]);
    }

    private function positive(float $amount): float
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('O valor precisa ser maior que zero.');
        }

        return round($amount, 6);
    }
}
