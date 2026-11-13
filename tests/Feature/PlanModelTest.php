<?php

declare(strict_types=1);

use App\Envelopes\Exceptions\FlowException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\PlanService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Erro de banco dentro de savepoint: não aborta a transação do teste (PostgreSQL). */
function dbFails(Closure $fn): void
{
    expect(fn () => DB::transaction($fn))->toThrow(QueryException::class);
}

function mkPlan(array $over = []): Plan
{
    static $n = 0;
    $n++;

    return Plan::create($over + ['name' => 'Plano '.$n, 'slug' => 'plano-'.$n, 'active' => true, 'is_public' => true, 'position' => $n]);
}

it('o plano Legado é ilimitado, tem todos os recursos e só existe um', function () {
    $svc = app(PlanService::class);
    $legacy = $svc->legacy();

    expect($legacy->slug)->toBe('legado')->and($legacy->limits)->toBe([])->and($legacy->is_public)->toBeFalse()
        ->and($svc->legacy()->id)->toBe($legacy->id)
        ->and(array_keys(array_filter($legacy->features)))->toEqualCanonicalizing(Plan::FEATURES);
});

it('empresas existentes passam para o Legado, isentas de cobrança e sem limite', function () {
    $a = Tenant::create(['name' => 'A']);
    $b = Tenant::create(['name' => 'B']);

    app(PlanService::class)->backfillLegacy();

    foreach ([$a, $b] as $t) {
        $s = $t->fresh()->subscription;
        expect($s->plan->slug)->toBe('legado')->and($s->billing_exempt)->toBeTrue()->and($s->status)->toBe('active');
        expect(app(PlanService::class)->effective($s)['limits'])->toBe([]);
    }
    // rodar de novo não duplica nem troca o plano de quem já tem
    $b->fresh()->subscription->update(['plan_id' => mkPlan()->id]);
    app(PlanService::class)->backfillLegacy();
    expect(Subscription::count())->toBe(2)->and($b->fresh()->subscription->plan->slug)->not->toBe('legado');
});

it('empresa nova recebe o plano padrão e entra em teste quando o plano tem dias de teste', function () {
    $padrao = mkPlan(['is_default' => true, 'trial' => ['days' => 14, 'requires_payment' => false, 'on_end' => 'block'], 'limits' => ['documents' => 5]]);

    $this->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => 'dono@acme.com',
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    $s = User::where('email', 'dono@acme.com')->firstOrFail()->tenant->subscription;
    expect($s->plan_id)->toBe($padrao->id)->and($s->status)->toBe('trialing')->and($s->billing_exempt)->toBeFalse()
        ->and($s->trial_ends_at->isSameDay(now()->addDays(14)))->toBeTrue();
});

it('sem plano padrão cadastrado a empresa nova cai no Legado; sem teste fica ativa', function () {
    $svc = app(PlanService::class);
    $t = Tenant::create(['name' => 'X']);
    expect($svc->assign($t, $svc->defaultPlan())->plan->slug)->toBe('legado');

    $pago = mkPlan(['is_default' => true]);
    $t2 = Tenant::create(['name' => 'Y']);
    $s2 = $svc->assign($t2, $svc->defaultPlan());
    expect($s2->plan_id)->toBe($pago->id)->and($s2->status)->toBe('active')->and($s2->trial_ends_at)->toBeNull();
});

it('só um plano é o padrão', function () {
    $a = mkPlan(['is_default' => true]);
    $b = mkPlan();
    app(PlanService::class)->makeDefault($b);

    expect($a->fresh()->is_default)->toBeFalse()->and($b->fresh()->is_default)->toBeTrue();
    dbFails(fn () => mkPlan(['is_default' => true]));
});

it('effective aplica o limite do teste e depois as sobrescritas da assinatura, chave por chave', function () {
    $svc = app(PlanService::class);
    $plan = mkPlan([
        'limits' => ['documents' => 100, 'users' => 5, 'storage_mb' => 1000],
        'features' => ['api' => true, 'white_label' => false],
        'trial' => ['days' => 7, 'on_end' => 'block', 'limits' => ['documents' => 5]],
        'consumption' => ['when' => 'send', 'balance' => 'quota', 'quota_amount' => 100, 'on_empty' => 'block'],
    ]);
    $t = Tenant::create(['name' => 'T']);
    $s = $svc->assign($t, $plan);

    expect($svc->effective($s)['limits'])->toEqual(['documents' => 5, 'users' => 5, 'storage_mb' => 1000]); // em teste

    $s->update(['status' => 'active', 'overrides' => ['limits' => ['storage_mb' => 200000, 'users' => null], 'features' => ['white_label' => true], 'consumption' => ['quota_amount' => 150]]]);
    $e = $svc->effective($s->fresh());
    expect($e['limits'])->toEqual(['documents' => 100, 'users' => null, 'storage_mb' => 200000]) // null = ilimitado
        ->and($e['features']['white_label'])->toBeTrue()->and($e['features']['api'])->toBeTrue()
        ->and($e['consumption']['quota_amount'])->toBe(150)->and($e['consumption']['when'])->toBe('send');
});

it('valores de plano são inteiros não negativos e chaves desconhecidas são recusadas', function () {
    $svc = app(PlanService::class);

    expect($svc->cleanLimits(['documents' => '50', 'users' => '', 'storage_mb' => 0]))->toBe(['documents' => 50, 'storage_mb' => 0]);
    expect(fn () => $svc->cleanLimits(['documents' => -1]))->toThrow(FlowException::class)
        ->and(fn () => $svc->cleanLimits(['documents' => '1,5']))->toThrow(FlowException::class)
        ->and(fn () => $svc->cleanLimits(['bitcoin' => 1]))->toThrow(FlowException::class);
    expect($svc->cleanFeatures(['api' => '1', 'selfie' => false]))->toBe(['api' => true, 'selfie' => false]);
    expect(fn () => $svc->cleanFeatures(['teletransporte' => true]))->toThrow(FlowException::class);
    expect(fn () => $svc->cleanConsumption(['when' => 'ontem']))->toThrow(FlowException::class);
});

it('preço em centavos inteiros, período válido e único por plano', function () {
    $p = mkPlan();
    PlanPrice::create(['plan_id' => $p->id, 'period' => 'month', 'amount_cents' => 4990, 'active' => true]);

    dbFails(fn () => PlanPrice::create(['plan_id' => $p->id, 'period' => 'month', 'amount_cents' => 100]));
    dbFails(fn () => PlanPrice::create(['plan_id' => $p->id, 'period' => 'decada', 'amount_cents' => 100]));
    dbFails(fn () => PlanPrice::create(['plan_id' => $p->id, 'period' => 'year', 'amount_cents' => -5]));
    expect($p->prices()->count())->toBe(1);
});

it('slug do plano é único e a assinatura é uma por empresa', function () {
    mkPlan(['slug' => 'pro']);
    dbFails(fn () => mkPlan(['slug' => 'pro']));

    $t = Tenant::create(['name' => 'Z']);
    $svc = app(PlanService::class);
    $svc->assign($t, mkPlan());
    $svc->assign($t, mkPlan()); // trocar de plano atualiza a mesma linha
    expect(Subscription::where('tenant_id', $t->id)->count())->toBe(1);
});
