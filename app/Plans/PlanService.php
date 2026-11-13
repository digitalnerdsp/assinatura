<?php

declare(strict_types=1);

namespace App\Plans;

use App\Envelopes\Exceptions\FlowException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Planos e assinaturas: plano padrão, Legado, troca de plano e o valor efetivo (plano + teste + sobrescritas). */
final class PlanService
{
    private const WHEN = ['send', 'complete'];

    private const BALANCE = ['quota', 'credits', 'both'];

    private const ON_EMPTY = ['block', 'overage', 'cap'];

    /** Plano de quem já usava o sistema: ilimitado, tudo ligado, sem cobrança. Criado sob demanda. */
    public function legacy(): Plan
    {
        return Plan::query()->where('slug', 'legado')->first() ?? Plan::query()->create([
            'name' => 'Legado', 'slug' => 'legado', 'description' => 'Empresas que já usavam a plataforma. Sem limites e sem cobrança.',
            'active' => true, 'is_public' => false, 'is_default' => false, 'position' => 9999,
            'limits' => [], 'features' => array_fill_keys(Plan::FEATURES, true),
        ]);
    }

    /** Plano das empresas novas: o marcado como padrão, ou o Legado enquanto não houver outro. */
    public function defaultPlan(): Plan
    {
        return Plan::query()->where('is_default', true)->where('active', true)->first() ?? $this->legacy();
    }

    public function makeDefault(Plan $plan): void
    {
        DB::transaction(function () use ($plan) {
            Plan::query()->where('is_default', true)->where('id', '!=', $plan->getKey())->update(['is_default' => false]);
            $plan->update(['is_default' => true]);
        });
    }

    /** Coloca a empresa no plano (cria a assinatura ou troca o plano da que existe). */
    public function assign(Tenant $tenant, Plan $plan, array $overrides = []): Subscription
    {
        return DB::transaction(function () use ($tenant, $plan, $overrides) {
            $sub = Subscription::query()->where('tenant_id', $tenant->getKey())->lockForUpdate()->first();
            $trialDays = (int) ($plan->trial['days'] ?? 0);

            if ($sub === null) {
                return Subscription::query()->create([
                    'tenant_id' => $tenant->getKey(), 'plan_id' => $plan->getKey(),
                    'status' => $trialDays > 0 ? 'trialing' : 'active',
                    'billing_exempt' => $plan->isLegacy(),
                    'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
                    'period_start' => now(), 'period_end' => now()->addMonth(),
                    'overrides' => $overrides,
                ]);
            }

            $sub->forceFill([
                'plan_id' => $plan->getKey(),
                'billing_exempt' => $plan->isLegacy() ? true : $sub->billing_exempt,
                'overrides' => $overrides !== [] ? $overrides : $sub->overrides,
            ])->save();

            return $sub->fresh();
        });
    }

    /** Assinatura da empresa; quem ainda não tem (conta criada por outro caminho) recebe a do plano padrão. */
    public function subscriptionFor(Tenant $tenant): Subscription
    {
        return Subscription::query()->where('tenant_id', $tenant->getKey())->first() ?? $this->assign($tenant, $this->defaultPlan());
    }

    /** Põe no Legado toda empresa sem assinatura. Seguro repetir. @return int quantas empresas entraram */
    public function backfillLegacy(): int
    {
        $legacy = $this->legacy();
        $n = 0;
        Tenant::query()->whereDoesntHave('subscription')->each(function (Tenant $t) use ($legacy, &$n) {
            $this->assign($t, $legacy);
            $n++;
        });

        return $n;
    }

    /**
     * Valor que vale hoje: plano, depois o limite do teste (se em teste), depois as sobrescritas da assinatura.
     * Em `limits`, `null` quer dizer ilimitado; ausente herda do plano.
     *
     * @return array{limits:array<string,?int>,features:array<string,bool>,consumption:array<string,mixed>}
     */
    public function effective(Subscription $sub): array
    {
        $plan = $sub->plan;
        $limits = $plan->limits ?? [];
        if ($sub->status === 'trialing' && ! empty($plan->trial['limits'])) {
            $limits = array_replace($limits, $plan->trial['limits']);
        }
        $o = $sub->overrides ?? [];

        return [
            'limits' => array_replace($limits, $o['limits'] ?? []),
            'features' => array_replace(array_fill_keys(Plan::FEATURES, false), $plan->features ?? [], $o['features'] ?? []),
            'consumption' => array_replace($plan->consumption ?? [], $o['consumption'] ?? []),
        ];
    }

    /** @param array<string,mixed> $in @return array<string,int> só as chaves preenchidas (vazio = ilimitado) */
    public function cleanLimits(array $in): array
    {
        $out = [];
        foreach ($in as $key => $value) {
            if (! in_array($key, Plan::LIMITS, true)) {
                throw FlowException::because('invalid_plan', "Limite desconhecido: {$key}.");
            }
            if ($value === null || $value === '') {
                continue;
            }
            $out[$key] = $this->nonNegativeInt($value, $key);
        }

        return $out;
    }

    /** @param array<string,mixed> $in @return array<string,bool> */
    public function cleanFeatures(array $in): array
    {
        $out = [];
        foreach ($in as $key => $value) {
            if (! in_array($key, Plan::FEATURES, true)) {
                throw FlowException::because('invalid_plan', "Recurso desconhecido: {$key}.");
            }
            $out[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $out;
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function cleanConsumption(array $in): array
    {
        $out = [];
        foreach ($in as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $out[$key] = match ($key) {
                'when' => $this->oneOf($value, self::WHEN, $key),
                'balance' => $this->oneOf($value, self::BALANCE, $key),
                'on_empty' => $this->oneOf($value, self::ON_EMPTY, $key),
                'refund_unsigned' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'quota_amount', 'overage_cents', 'cap' => $this->nonNegativeInt($value, $key),
                default => throw FlowException::because('invalid_plan', "Regra de consumo desconhecida: {$key}."),
            };
        }

        return $out;
    }

    private function nonNegativeInt(mixed $value, string $key): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw FlowException::because('invalid_plan', "O valor de {$key} precisa ser um número inteiro, zero ou maior.");
        }
        if ((int) $value < 0) {
            throw FlowException::because('invalid_plan', "O valor de {$key} não pode ser negativo.");
        }

        return (int) $value;
    }

    private function oneOf(mixed $value, array $allowed, string $key): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw FlowException::because('invalid_plan', "Valor inválido para {$key}.");
        }

        return $value;
    }
}
