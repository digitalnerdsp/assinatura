<?php

declare(strict_types=1);

use App\Assistant\Pricing\AssistantPricing;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function afSuper(): User
{
    $u = User::create(['tenant_id' => Tenant::create(['name' => 'Plataforma '.uniqid()])->id, 'name' => 'Super', 'email' => 'super'.uniqid().'@af.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['platform_role' => 'super_admin'])->save();

    return $u;
}

function afSave(): void
{
    app(AssistantPricing::class)->save([
        'base_model' => 'gpt-6-luna', 'brl_per_usd' => '5,90', 'margin_percent' => '100', 'fee_percent' => '0',
        'prices' => [['model' => 'gpt-6-luna', 'input' => '0,10', 'cached' => '0,01', 'output' => '0,50']],
    ]);
}

function afPlan(int $tokens, ?string $monthlyReais): Plan
{
    $plan = Plan::create(['name' => 'Plano '.uniqid(), 'slug' => 'p-'.uniqid(), 'active' => true, 'features' => ['assistant' => true], 'limits' => ['assistant_tokens' => $tokens]]);
    if ($monthlyReais !== null) {
        PlanPrice::create(['plan_id' => $plan->id, 'period' => 'month', 'amount_cents' => (int) round(((float) str_replace(',', '.', $monthlyReais)) * 100), 'active' => true]);
    }

    return $plan;
}

it('o custo máximo da franquia vem do custo do token base', function () {
    afSave();

    expect(app(AssistantPricing::class)->franchiseCostBrl(1_000_000))->toBe(0.59)->and(app(AssistantPricing::class)->franchiseCostBrl(50_000))->toBe(0.0295);
    expect(app(AssistantPricing::class)->franchiseAlertPercent())->toBe(10.0);
});

it('o formulário do plano mostra o custo da franquia e quanto isso pesa na mensalidade', function () {
    afSave();
    $plan = afPlan(50_000_000, '49,90'); // 50 milhões de tokens equivalentes: R$ 29,50 de custo

    $this->actingAs(afSuper())->get(route('admin.plans.edit', $plan))->assertOk()
        ->assertSee('Custo máximo da franquia')->assertSee('R$ 29,50')->assertSee('59%')->assertSee('acima de 10%');
});

it('franquia barata não gera alerta', function () {
    afSave();
    $plan = afPlan(50_000, '49,90');

    $this->actingAs(afSuper())->get(route('admin.plans.edit', $plan))->assertOk()->assertSee('R$ 0,03')->assertDontSee('acima de 10%');
});

it('sem a tabela de preços salva, o plano explica o que falta', function () {
    $plan = afPlan(50_000, '49,90');

    $this->actingAs(afSuper())->get(route('admin.plans.edit', $plan))->assertOk()->assertSee('Salve a tabela de preços');
});

it('o limite do alerta é configurável na economia dos tokens', function () {
    afSave();
    $super = afSuper();
    $base = ['base_model' => 'gpt-6-luna', 'brl_per_usd' => '5,90', 'margin_percent' => '100', 'fee_percent' => '0', 'prices' => [['model' => 'gpt-6-luna', 'input' => '0,10', 'cached' => '0,01', 'output' => '0,50']]];

    $this->actingAs($super)->put(route('admin.economy.update'), $base + ['franchise_alert_percent' => '25'])->assertRedirect();
    expect(app(AssistantPricing::class)->franchiseAlertPercent())->toBe(25.0);

    $this->actingAs($super)->put(route('admin.economy.update'), $base + ['franchise_alert_percent' => '0'])->assertSessionHasErrors('franchise_alert_percent');
    $this->actingAs($super)->put(route('admin.economy.update'), $base + ['franchise_alert_percent' => '150'])->assertSessionHasErrors('franchise_alert_percent');
});
