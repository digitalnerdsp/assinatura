<?php

declare(strict_types=1);

namespace App\Plans;

use App\Envelopes\Exceptions\FlowException;
use App\Models\Envelope;
use App\Models\Subscription;
use App\Models\UsageEntry;
use Illuminate\Support\Facades\DB;

/**
 * Desconta documentos da cota do período e/ou dos créditos, sempre dentro de transação com trava de linha na
 * assinatura (dois envios ao mesmo tempo nunca passam do saldo) e deixa cada lançamento no extrato de uso.
 */
final class UsageLedger
{
    /**
     * @param  string  $when  'send' ou 'complete': só desconta se for o momento definido no plano
     *
     * @throws FlowException documents_limit, só no envio, quando não há saldo e o plano manda bloquear
     */
    public function charge(Envelope $envelope, string $when): void
    {
        DB::transaction(function () use ($envelope, $when) {
            $sub = $this->lock($envelope);
            $r = Entitlements::rulesFrom(app(PlanService::class)->effective($sub));
            if ($r['when'] !== $when) {
                return;
            }
            if (UsageEntry::query()->where('envelope_id', $envelope->getKey())->whereIn('kind', ['quota', 'credit'])->exists()) {
                return; // já descontado
            }
            $this->rollPeriod($sub);

            $mode = $r['balance'];
            $quota = $r['quota'];
            if ($mode !== 'credits' && $quota === null) {
                return; // sem limite: nada a descontar
            }
            $usesQuota = $mode !== 'credits' && $quota !== null;

            if ($usesQuota && $sub->quota_used < $quota) {
                $sub->quota_used++;
                $sub->save();
                $this->entry($envelope, 'quota', -1, $quota - $sub->quota_used, 'Cota do período');

                return;
            }
            if ($mode !== 'quota' && $sub->credits_balance > 0) {
                $sub->credits_balance--;
                $sub->save();
                $this->entry($envelope, 'credit', -1, $sub->credits_balance, 'Pacote de créditos');

                return;
            }

            // Sem saldo.
            $overage = $this->overageCount($sub);
            if ($when === 'send') {
                $allowed = $r['on_empty'] === 'overage' || ($r['on_empty'] === 'cap' && $overage < $r['cap']);
                if (! $allowed) {
                    throw FlowException::because('documents_limit', 'Você usou todos os documentos do seu plano neste período. Peça mais documentos ou troque de plano para enviar novos.');
                }
            }
            // Excedente (ou documento já enviado que concluiu sem saldo): registra e segue.
            $note = $r['overage_cents'] > 0
                ? 'Envio acima da cota (excedente de R$ '.number_format($r['overage_cents'] / 100, 2, ',', '.').')'
                : 'Envio acima da cota (excedente)';
            if ($usesQuota) {
                $sub->quota_used++;
                $sub->save();
                $this->entry($envelope, 'quota', -1, $quota - $sub->quota_used, $note);
            } else {
                $this->entry($envelope, 'adjust', -1, $sub->credits_balance, $note);
            }
        });
    }

    /** Devolve o desconto de um documento cancelado ou expirado sem assinatura, se o plano pedir. */
    public function refund(Envelope $envelope): void
    {
        DB::transaction(function () use ($envelope) {
            $sub = $this->lock($envelope);
            $r = Entitlements::rulesFrom(app(PlanService::class)->effective($sub));
            if (! $r['refund_unsigned']) {
                return;
            }
            $charge = UsageEntry::query()->where('envelope_id', $envelope->getKey())->whereIn('kind', ['quota', 'credit'])->first();
            if ($charge === null || UsageEntry::query()->where('envelope_id', $envelope->getKey())->where('kind', 'refund')->exists()) {
                return;
            }
            if ($envelope->signers()->where('status', 'signed')->exists()) {
                return; // alguém já assinou: o documento foi usado
            }

            if ($charge->kind === 'quota') {
                if ($sub->period_start !== null && $charge->occurred_at->lt($sub->period_start)) {
                    return; // era de outro período: a cota daquele período já virou
                }
                $sub->quota_used = max(0, $sub->quota_used - 1);
                $balance = ($r['quota'] ?? 0) - $sub->quota_used;
            } else {
                $sub->credits_balance++;
                $balance = $sub->credits_balance;
            }
            $sub->save();
            $this->entry($envelope, 'refund', 1, $balance, 'Devolvido: documento cancelado ou expirado sem assinatura');
        });
    }

    private function lock(Envelope $envelope): Subscription
    {
        $tenant = $envelope->tenant;
        app(PlanService::class)->subscriptionFor($tenant);

        return Subscription::query()->where('tenant_id', $tenant->getKey())->lockForUpdate()->with('plan')->firstOrFail();
    }

    /** Vira o período quando o fim passou (a cobrança, na Fase 4, assume a renovação). */
    private function rollPeriod(Subscription $sub): void
    {
        if ($sub->period_end === null || ! $sub->period_end->isPast()) {
            return;
        }
        $start = $sub->period_end;
        $end = $start->copy()->addMonth();
        for ($i = 0; $end->isPast() && $i < 240; $i++) {
            $start = $end;
            $end = $start->copy()->addMonth();
        }
        $sub->forceFill(['period_start' => $start, 'period_end' => $end, 'quota_used' => 0])->save();
    }

    private function overageCount(Subscription $sub): int
    {
        return UsageEntry::query()->where('tenant_id', $sub->tenant_id)->where('note', 'like', '%excedente%')
            ->when($sub->period_start, fn ($q, $from) => $q->where('occurred_at', '>=', $from))->count();
    }

    private function entry(Envelope $envelope, string $kind, int $delta, int $balanceAfter, string $note): void
    {
        UsageEntry::query()->create([
            'tenant_id' => $envelope->tenant_id, 'envelope_id' => $envelope->getKey(),
            'kind' => $kind, 'delta' => $delta, 'balance_after' => $balanceAfter, 'note' => $note, 'occurred_at' => now(),
        ]);
    }
}
