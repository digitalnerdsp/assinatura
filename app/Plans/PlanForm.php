<?php

declare(strict_types=1);

namespace App\Plans;

use App\Envelopes\Exceptions\FlowException;
use App\Models\Plan;
use App\Models\PlanPrice;

/** Converte o que a tela de planos e de assinaturas envia (reais, caixas de marcar, vazios) no que é guardado. */
final class PlanForm
{
    public function __construct(private readonly PlanService $plans)
    {
    }

    /** "49,90" ou "49.90" → 4990 (centavos inteiros); vazio → null; qualquer outra coisa recusa. */
    public static function money(?string $value): ?int
    {
        $v = trim(str_replace(['R$', ' '], '', (string) $value));
        if ($v === '') {
            return null;
        }
        if (! preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $v)) {
            throw FlowException::because('invalid_plan', 'Valor em reais inválido: use números como 49,90 (sem negativo).');
        }
        [$int, $dec] = array_pad(preg_split('/[.,]/', $v), 2, '0');

        return (int) $int * 100 + (int) str_pad($dec, 2, '0');
    }

    /**
     * Atributos do plano (sem nome, que o controller valida) e os preços por período.
     *
     * @param  array<string,mixed>  $in
     * @return array{attrs:array<string,mixed>,prices:list<array<string,mixed>>}
     */
    public function plan(array $in): array
    {
        $limits = $this->plans->cleanLimits((array) ($in['limits'] ?? []));

        $trialIn = (array) ($in['trial'] ?? []);
        $days = trim((string) ($trialIn['days'] ?? ''));
        $trial = [];
        if ($days !== '' && $days !== '0') {
            if (! ctype_digit($days) || (int) $days > 365) {
                throw FlowException::because('invalid_plan', 'Os dias de teste devem ser um número de 1 a 365 (0 = sem teste).');
            }
            $onEnd = (string) ($trialIn['on_end'] ?? 'block');
            if (! in_array($onEnd, ['convert', 'free_plan', 'block'], true)) {
                throw FlowException::because('invalid_plan', 'O que acontece ao fim do teste é inválido.');
            }
            $trial = ['days' => (int) $days, 'requires_payment' => ! empty($trialIn['requires_payment']), 'on_end' => $onEnd, 'limits' => $this->plans->cleanLimits((array) ($trialIn['limits'] ?? []))];
        }

        $c = (array) ($in['consumption'] ?? []);
        $overage = self::money($c['overage'] ?? null);
        unset($c['overage']);
        $c['refund_unsigned'] = ! empty($c['refund_unsigned']);
        if ($overage !== null) {
            $c['overage_cents'] = $overage;
        }
        $consumption = $this->plans->cleanConsumption($c);

        $features = [];
        foreach (Plan::FEATURES as $key) {
            $features[$key] = ! empty($in['features'][$key]);
        }

        $prices = [];
        foreach (array_keys(PlanPrice::PERIODS) as $period) {
            $row = (array) ($in['prices'][$period] ?? []);
            $cents = self::money($row['amount'] ?? null);
            if ($cents === null) {
                continue;
            }
            $label = trim((string) ($row['discount_label'] ?? ''));
            $prices[] = ['period' => $period, 'amount_cents' => $cents, 'discount_label' => $label === '' ? null : mb_substr($label, 0, 60), 'active' => ! empty($row['active'])];
        }

        return ['attrs' => ['limits' => $limits, 'trial' => $trial, 'consumption' => $consumption, 'features' => $this->plans->cleanFeatures($features)], 'prices' => $prices];
    }

    /**
     * Sobrescritas da assinatura. Vazio herda do plano; "ilimitado" grava null; recursos: on / off / inherit.
     *
     * @param  array<string,mixed>  $in
     * @return array<string,mixed>
     */
    public function overrides(array $in): array
    {
        $out = [];

        $limitsIn = (array) ($in['limits'] ?? []);
        $unlimited = (array) ($in['unlimited'] ?? []);
        $limits = $this->plans->cleanLimits(array_diff_key($limitsIn, $unlimited));
        foreach (array_keys($unlimited) as $key) {
            if (in_array($key, Plan::LIMITS, true) && ! empty($unlimited[$key])) {
                $limits[$key] = null;
            }
        }
        if ($limits !== []) {
            $out['limits'] = $limits;
        }

        $features = [];
        foreach ((array) ($in['features'] ?? []) as $key => $state) {
            if ($state === 'on' || $state === 'off') {
                $features[$key] = $state === 'on';
            }
        }
        if ($features !== []) {
            $out['features'] = $this->plans->cleanFeatures($features);
        }

        $c = (array) ($in['consumption'] ?? []);
        $overage = self::money($c['overage'] ?? null);
        unset($c['overage']);
        if ($overage !== null) {
            $c['overage_cents'] = $overage;
        }
        if (($c['refund_unsigned'] ?? '') === 'on' || ($c['refund_unsigned'] ?? '') === 'off') {
            $c['refund_unsigned'] = $c['refund_unsigned'] === 'on';
        } else {
            unset($c['refund_unsigned']);
        }
        $consumption = $this->plans->cleanConsumption($c);
        if ($consumption !== []) {
            $out['consumption'] = $consumption;
        }

        $price = self::money($in['price'] ?? null);
        if ($price !== null) {
            $out['price_cents'] = $price;
        }

        return $out;
    }
}
