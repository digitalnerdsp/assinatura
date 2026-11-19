<?php

declare(strict_types=1);

use App\Assistant\AssistantSettings;
use App\Assistant\Metering\MetaUsageLedger;
use App\Assistant\Metering\TokenWallet;
use App\Models\MetaMessageUsage;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Tenant;
use App\Plans\Entitlements;
use App\Plans\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-12-10 12:00:00');
    Setting::put('assistant.model_weights', json_encode(['luna' => 1, 'grande' => 3]));
    Setting::put('assistant.markup_percent', '20');
    Setting::put('assistant.overrun_cap', '50');
    Setting::put('assistant.output_factor', '4');
    Setting::put('assistant.cached_factor', '0.1');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function amTenant(array $planAttrs = []): Tenant
{
    static $n = 0;
    $n++;
    $p = Plan::create($planAttrs + ['name' => 'AM'.$n, 'slug' => 'am-'.$n, 'active' => true]);
    $t = Tenant::create(['name' => 'Empresa AM '.$n]);
    app(PlanService::class)->assign($t, $p);

    return $t->fresh();
}

// ------------------------------------------------------------------ tokens

it('o débito pondera pelo peso do modelo e pelos fatores de saída e de cache', function () {
    $t = amTenant();
    $w = app(TokenWallet::class);
    $w->grantPlanPackage($t, 10000, '2026-12');

    // luna (peso 1): (1000-200) + 200*0,1 + 100*4 = 800 + 20 + 400 = 1220
    expect($w->debit($t, 'luna', 1000, 200, 100, null))->toBe(1220)->and($w->balance($t))->toBe(8780);
    // grande (peso 3): 1220 * 3 = 3660
    expect($w->debit($t, 'grande', 1000, 200, 100, null))->toBe(3660)->and($w->balance($t))->toBe(5120);
});

it('modelo sem peso cadastrado conta com peso 1', function () {
    $t = amTenant();
    $w = app(TokenWallet::class);
    $w->grantPlanPackage($t, 1000, '2026-12');

    expect($w->debit($t, 'desconhecido', 100, 0, 0, null))->toBe(100);
});

it('usa primeiro o pacote do plano e depois os extras que vencem primeiro', function () {
    $t = amTenant();
    $w = app(TokenWallet::class);
    $w->grantPlanPackage($t, 100, '2026-12');
    $w->addPurchase($t, 300, new DateTimeImmutable('2027-12-01'), 'extra longo');
    $w->addPurchase($t, 200, new DateTimeImmutable('2027-01-15'), 'extra curto');

    $w->debit($t, 'luna', 150, 0, 0, null); // 100 do plano + 50 do extra curto

    $left = fn (string $note) => \App\Models\AssistantTokenEntry::query()->where('tenant_id', $t->id)->where('note', $note)->first();
    $remaining = fn ($entry) => $entry->tokens + (int) \App\Models\AssistantTokenEntry::query()->where('source_id', $entry->id)->sum('tokens');
    expect($remaining($left('extra curto')))->toBe(150)->and($remaining($left('extra longo')))->toBe(300)->and($w->balance($t))->toBe(450);
});

it('saldo zero impede começar uma conversa', function () {
    $t = amTenant();
    $w = app(TokenWallet::class);

    expect($w->canStart($t))->toBeFalse();
    $w->grantPlanPackage($t, 10, '2026-12');
    expect($w->canStart($t))->toBeTrue();
    $w->debit($t, 'luna', 10, 0, 0, null);
    expect($w->canStart($t))->toBeFalse();
});

it('uma conversa em andamento pode passar do zero, mas o saldo nunca desce além do limite de excesso', function () {
    $t = amTenant();
    $w = app(TokenWallet::class);
    $w->grantPlanPackage($t, 100, '2026-12');

    expect($w->debit($t, 'luna', 120, 0, 0, null))->toBe(120)->and($w->balance($t))->toBe(-20);
    // a segunda chamada só vai até o piso (-50): o resto a plataforma absorve
    expect($w->debit($t, 'luna', 120, 0, 0, null))->toBe(30)->and($w->balance($t))->toBe(-50);
    expect($w->debit($t, 'luna', 500, 0, 0, null))->toBe(0)->and($w->balance($t))->toBe(-50);
});

it('o pacote do plano de um período é concedido uma vez só', function () {
    $t = amTenant();
    $w = app(TokenWallet::class);

    $w->grantPlanPackage($t, 1000, '2026-12');
    $w->grantPlanPackage($t, 1000, '2026-12');
    expect($w->balance($t))->toBe(1000);

    $w->grantPlanPackage($t, 1000, '2027-01');
    expect($w->balance($t))->toBe(2000);
});

it('o pacote do plano vence no fim do mês e o do mês passado não conta', function () {
    $t = amTenant();
    $w = app(TokenWallet::class);
    $w->grantPlanPackage($t, 1000, '2026-11'); // vencido em 30/11

    expect($w->balance($t))->toBe(0);
    $w->grantPlanPackage($t, 500, '2026-12');
    expect($w->balance($t))->toBe(500);
});

it('compra com vencimento passado não conta e compra sem vencimento conta', function () {
    $t = amTenant();
    $w = app(TokenWallet::class);
    $w->addPurchase($t, 400, new DateTimeImmutable('2026-12-01'), 'vencida');
    $w->addPurchase($t, 250, null, 'sem validade');

    expect($w->balance($t))->toBe(250);
});

it('o saldo de uma empresa não mistura com o de outra', function () {
    $a = amTenant();
    $b = amTenant();
    $w = app(TokenWallet::class);
    $w->grantPlanPackage($a, 500, '2026-12');

    expect($w->balance($a))->toBe(500)->and($w->balance($b))->toBe(0);
    $w->debit($b, 'luna', 10, 0, 0, null); // a empresa B usa (dentro do limite de excesso)
    expect($w->balance($a))->toBe(500)->and($w->balance($b))->toBe(-10);
});

it('cada débito guarda o uso real (entrada, cache e saída) e a conversa', function () {
    $t = amTenant();
    $w = app(TokenWallet::class);
    $w->grantPlanPackage($t, 5000, '2026-12');

    $w->debit($t, 'luna', 1000, 200, 100, 'conversa-1');

    $row = \App\Models\AssistantTokenEntry::query()->where('kind', 'usage')->first();
    expect($row->model)->toBe('luna')->and($row->input_tokens)->toBe(1000)->and($row->cached_tokens)->toBe(200)->and($row->output_tokens)->toBe(100)->and($row->conversation_id)->toBe('conversa-1')->and($row->tokens)->toBe(-1220);
});

it('ajuste manual do administrador soma ou subtrai e fica registrado com nota', function () {
    $t = amTenant();
    $w = app(TokenWallet::class);
    $w->adjust($t, 700, 'cortesia');
    expect($w->balance($t))->toBe(700);
    $w->adjust($t, -200, 'correção');
    expect($w->balance($t))->toBe(500)->and(\App\Models\AssistantTokenEntry::query()->where('kind', 'adjust')->pluck('note')->all())->toBe(['cortesia', 'correção']);
});

// ------------------------------------------------------------------ plano e recurso

it('o recurso "assistente" vem desligado por padrão e liga pelo plano; o pacote vem do plano ou do padrão da plataforma', function () {
    $off = amTenant();
    expect(Entitlements::for($off)->feature('assistant'))->toBeFalse();
    expect(in_array('assistant', Plan::FEATURES, true))->toBeTrue()->and(Plan::FEATURE_LABELS['assistant'])->toBe('Assistente no WhatsApp')
        ->and(in_array('assistant_tokens', Plan::LIMITS, true))->toBeTrue();

    Setting::put('assistant.default_package_tokens', '30000');
    $on = amTenant(['features' => ['assistant' => true]]);
    expect(Entitlements::for($on)->feature('assistant'))->toBeTrue();
    expect(app(TokenWallet::class)->planPackageFor($on))->toBe(30000);

    $custom = amTenant(['features' => ['assistant' => true], 'limits' => ['assistant_tokens' => 80000]]);
    expect(app(TokenWallet::class)->planPackageFor($custom))->toBe(80000);
    expect(app(TokenWallet::class)->planPackageFor($off))->toBe(0);
});

it('ensurePlanPackage concede o pacote do mês atual uma vez só', function () {
    Setting::put('assistant.default_package_tokens', '1000');
    $t = amTenant(['features' => ['assistant' => true]]);
    $w = app(TokenWallet::class);

    $w->ensurePlanPackage($t);
    $w->ensurePlanPackage($t);

    expect($w->balance($t))->toBe(1000);
    CarbonImmutable::setTestNow('2027-01-03 09:00:00');
    $w->ensurePlanPackage($t);
    expect($w->balance($t))->toBe(1000)->and(\App\Models\AssistantTokenEntry::query()->where('kind', 'plan')->count())->toBe(2); // o de dezembro venceu; o de janeiro vale
});

// ------------------------------------------------------------------ mensagens da Meta

it('registra a mensagem cobrável com o acréscimo, guardando o valor original e o cobrado separados', function () {
    $t = amTenant();
    $l = app(MetaUsageLedger::class);

    $l->record($t, 'wamid.1', 'utility', true, 0.05, 'BRL', CarbonImmutable::parse('2026-12-10 15:00:00'));

    $row = MetaMessageUsage::query()->first();
    expect((float) $row->meta_amount)->toBe(0.05)->and((float) $row->charged_amount)->toBe(0.06)->and((float) $row->markup_percent)->toBe(20.0)
        ->and($row->period)->toBe('2026-12')->and($row->currency)->toBe('BRL')->and($row->billable)->toBeTrue();
});

it('a mesma mensagem não é cobrada duas vezes', function () {
    $t = amTenant();
    $l = app(MetaUsageLedger::class);

    $l->record($t, 'wamid.1', 'utility', true, 0.05, 'BRL', CarbonImmutable::now());
    $l->record($t, 'wamid.1', 'utility', true, 0.05, 'BRL', CarbonImmutable::now());

    expect(MetaMessageUsage::query()->count())->toBe(1);
});

it('mensagem não cobrável fica registrada com valor zero', function () {
    $t = amTenant();
    app(MetaUsageLedger::class)->record($t, 'wamid.2', 'service', false, 0.0, 'BRL', CarbonImmutable::now());

    $row = MetaMessageUsage::query()->first();
    expect($row->billable)->toBeFalse()->and((float) $row->charged_amount)->toBe(0.0);
});

it('o total do mês soma o original, o cobrado e a quantidade, só da empresa e do mês', function () {
    $a = amTenant();
    $b = amTenant();
    $l = app(MetaUsageLedger::class);
    $l->record($a, 'w1', 'utility', true, 0.05, 'BRL', CarbonImmutable::parse('2026-12-02'));
    $l->record($a, 'w2', 'marketing', true, 0.35, 'BRL', CarbonImmutable::parse('2026-12-20'));
    $l->record($a, 'w3', 'utility', true, 0.05, 'BRL', CarbonImmutable::parse('2026-11-30 12:00:00'));
    $l->record($b, 'w4', 'utility', true, 9.99, 'BRL', CarbonImmutable::parse('2026-12-05'));

    $total = $l->monthTotal($a, '2026-12');

    expect($total['count'])->toBe(2)->and(round($total['meta'], 2))->toBe(0.40)->and(round($total['charged'], 2))->toBe(0.48);
});

it('o período vem do horário de Brasília, não do UTC', function () {
    $t = amTenant();
    // 01/01 02:00 UTC ainda é 31/12 em Brasília
    app(MetaUsageLedger::class)->record($t, 'w9', 'utility', true, 0.05, 'BRL', CarbonImmutable::parse('2027-01-01 02:00:00', 'UTC'));

    expect(MetaMessageUsage::query()->first()->period)->toBe('2026-12');
});

it('as configurações da plataforma têm padrões seguros quando nada foi salvo', function () {
    Setting::query()->where('key', 'like', 'assistant.%')->delete();
    $s = app(AssistantSettings::class);

    expect($s->markupPercent())->toBe(0.0)->and($s->modelWeight('qualquer'))->toBe(1.0)->and($s->overrunCap())->toBeGreaterThan(0)
        ->and($s->defaultPackageTokens())->toBeGreaterThan(0)->and($s->outputFactor())->toBe(4.0)->and($s->cachedFactor())->toBe(0.1);
});
