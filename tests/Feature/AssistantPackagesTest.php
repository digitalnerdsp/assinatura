<?php

declare(strict_types=1);

use App\Assistant\Metering\TokenWallet;
use App\Assistant\Pricing\AssistantPricing;
use App\Assistant\Pricing\PackageOrders;
use App\Assistant\Pricing\TokenEconomics;
use App\Models\AssistantPackage;
use App\Models\AssistantPackageOrder;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-12-10 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function apkSuper(): User
{
    $u = User::create(['tenant_id' => Tenant::create(['name' => 'Plataforma '.uniqid()])->id, 'name' => 'Super', 'email' => 'super'.uniqid().'@pk.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['platform_role' => 'super_admin'])->save();

    return $u;
}

function apkOwner(bool $feature = true): User
{
    static $n = 0;
    $n++;
    $plan = Plan::create(['name' => 'PK'.$n, 'slug' => 'pk-'.$n, 'active' => true, 'features' => ['assistant' => $feature]]);
    $tenant = Tenant::create(['name' => 'Empresa PK '.$n]);
    app(PlanService::class)->assign($tenant, $plan);

    return User::create(['tenant_id' => $tenant->id, 'name' => 'Dono', 'email' => "dono{$n}@pk.test", 'password' => 'senha-muito-forte', 'role' => 'owner']);
}

function apkPackage(array $o = []): AssistantPackage
{
    return AssistantPackage::query()->create($o + ['name' => 'Pacote P', 'tokens' => 1_000_000, 'price_cents' => 2990, 'validity_months' => 12, 'active' => true, 'position' => 1]);
}

function apkPrices(): void
{
    app(AssistantPricing::class)->save([
        'base_model' => 'gpt-6-luna', 'brl_per_usd' => '5,90', 'margin_percent' => '100', 'fee_percent' => '0',
        'prices' => [['model' => 'gpt-6-luna', 'input' => '0,10', 'cached' => '0,01', 'output' => '0,50']],
    ]);
}

// ------------------------------------------------------------------ catálogo (super admin)

it('o super administrador cria, edita e desativa pacotes; o preço aceita vírgula', function () {
    $super = apkSuper();

    $this->actingAs($super)->post(route('admin.packages.store'), ['name' => 'Pacote 1 milhão', 'tokens' => '1.000.000', 'price' => '29,90', 'validity_months' => '12', 'active' => '1'])->assertRedirect(route('admin.packages'));
    $p = AssistantPackage::query()->first();
    expect($p->name)->toBe('Pacote 1 milhão')->and($p->tokens)->toBe(1_000_000)->and($p->price_cents)->toBe(2990)->and($p->active)->toBeTrue();

    $this->actingAs($super)->put(route('admin.packages.update', $p), ['name' => 'Pacote grande', 'tokens' => '5000000', 'price' => '99', 'validity_months' => '6'])->assertRedirect(route('admin.packages'));
    $p->refresh();
    expect($p->name)->toBe('Pacote grande')->and($p->tokens)->toBe(5_000_000)->and($p->price_cents)->toBe(9900)->and($p->validity_months)->toBe(6)->and($p->active)->toBeFalse(); // sem o interruptor, fica desativado
});

it('valida o pacote: nome, tokens, preço e validade', function () {
    $super = apkSuper();
    $ok = ['name' => 'X', 'tokens' => '1000', 'price' => '10', 'validity_months' => '12'];

    $this->actingAs($super)->post(route('admin.packages.store'), ['name' => ''] + $ok)->assertSessionHasErrors('name');
    $this->actingAs($super)->post(route('admin.packages.store'), ['tokens' => '0'] + $ok)->assertSessionHasErrors('tokens');
    $this->actingAs($super)->post(route('admin.packages.store'), ['tokens' => '2000000000'] + $ok)->assertSessionHasErrors('tokens');
    $this->actingAs($super)->post(route('admin.packages.store'), ['price' => 'abc'] + $ok)->assertSessionHasErrors('price');
    $this->actingAs($super)->post(route('admin.packages.store'), ['price' => '-5'] + $ok)->assertSessionHasErrors('price');
    $this->actingAs($super)->post(route('admin.packages.store'), ['validity_months' => '0'] + $ok)->assertSessionHasErrors('validity_months');
    $this->actingAs($super)->post(route('admin.packages.store'), ['validity_months' => '61'] + $ok)->assertSessionHasErrors('validity_months');
    expect(AssistantPackage::query()->count())->toBe(0);
});

it('a tela mostra o custo, o preço mínimo sugerido e a margem de cada pacote', function () {
    apkPrices();
    apkPackage(['name' => 'Pacote A', 'tokens' => 10_000_000, 'price_cents' => 1500]); // custo R$ 5,90, venda mínima R$ 11,80

    $this->actingAs(apkSuper())->get(route('admin.packages'))->assertOk()->assertSee('Pacote A')->assertSee('R$ 5,90')->assertSee('R$ 11,80')->assertSee('154%');
});

it('só o super administrador mexe nos pacotes e nos pedidos', function () {
    $owner = apkOwner();
    $admin = User::create(['tenant_id' => Tenant::create(['name' => 'Op'])->id, 'name' => 'A', 'email' => 'op@pk.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $admin->forceFill(['platform_role' => 'admin'])->save();
    $p = apkPackage();
    $order = app(PackageOrders::class)->request($owner, $p);

    $this->actingAs($owner)->get(route('admin.packages'))->assertNotFound();
    $this->actingAs($admin)->get(route('admin.packages'))->assertForbidden();
    $this->actingAs($admin)->post(route('admin.packages.approve', $order))->assertForbidden();
    expect($order->refresh()->status)->toBe('pending');
});

it('o menu da plataforma tem os pacotes de tokens', function () {
    expect(collect(app(\App\Themes\MenuBuilder::class)->for(apkSuper()))->pluck('label')->all())->toContain('Pacotes de tokens');
});

// ------------------------------------------------------------------ painel da empresa

it('a empresa vê só os pacotes ativos, com preço e quantas conversas rendem', function () {
    $owner = apkOwner();
    apkPackage(['name' => 'Pacote Ativo', 'tokens' => 800_000, 'price_cents' => 4990]);
    apkPackage(['name' => 'Pacote Escondido', 'active' => false]);

    $this->actingAs($owner)->get(route('assistant.index'))->assertOk()->assertSee('Pacote Ativo')->assertSee('R$ 49,90')->assertSee('100 conversas')->assertDontSee('Pacote Escondido');
});

it('o dono pede um pacote e o pedido guarda o preço da hora', function () {
    $owner = apkOwner();
    $p = apkPackage();

    $this->actingAs($owner)->post(route('assistant.packages.order', $p))->assertRedirect(route('assistant.index'));
    $p->forceFill(['price_cents' => 9999, 'tokens' => 1])->save(); // mudar o pacote depois não muda o pedido

    $o = AssistantPackageOrder::query()->first();
    expect($o->tenant_id)->toBe($owner->tenant_id)->and($o->status)->toBe('pending')->and($o->tokens)->toBe(1_000_000)->and($o->price_cents)->toBe(2990)->and($o->name)->toBe('Pacote P')->and($o->requested_by)->toBe($owner->id);
    $this->actingAs($owner)->get(route('assistant.index'))->assertOk()->assertSee('aguardando');
});

it('pedido repetido do mesmo pacote e mais de 3 pendentes são recusados', function () {
    $owner = apkOwner();
    $svc = app(PackageOrders::class);
    $a = apkPackage(['name' => 'A']);
    $svc->request($owner, $a);

    expect(fn () => $svc->request($owner, $a))->toThrow(DomainException::class);

    $svc->request($owner, apkPackage(['name' => 'B']));
    $svc->request($owner, apkPackage(['name' => 'C']));
    expect(fn () => $svc->request($owner, apkPackage(['name' => 'D'])))->toThrow(DomainException::class)
        ->and(AssistantPackageOrder::query()->count())->toBe(3);
});

it('pacote desativado, empresa sem o recurso e quem não é dono não conseguem pedir', function () {
    $owner = apkOwner();
    $off = apkPackage(['active' => false]);
    $on = apkPackage();
    $member = User::create(['tenant_id' => $owner->tenant_id, 'name' => 'M', 'email' => 'm@pk.test', 'password' => 'senha-muito-forte', 'role' => 'member']);
    $noFeature = apkOwner(false);

    $this->actingAs($owner)->post(route('assistant.packages.order', $off))->assertSessionHasErrors('package');
    $this->actingAs($member)->post(route('assistant.packages.order', $on))->assertForbidden();
    $this->actingAs($noFeature)->post(route('assistant.packages.order', $on))->assertForbidden();
    expect(AssistantPackageOrder::query()->count())->toBe(0);
});

it('o dono cancela o pedido pendente dele, e só o dele', function () {
    $a = apkOwner();
    $b = apkOwner();
    $p = apkPackage();
    $order = app(PackageOrders::class)->request($a, $p);

    $this->actingAs($b)->delete(route('assistant.orders.cancel', $order))->assertNotFound();
    expect($order->refresh()->status)->toBe('pending');

    $this->actingAs($a)->delete(route('assistant.orders.cancel', $order))->assertRedirect();
    expect($order->refresh()->status)->toBe('canceled');

    $this->actingAs($a)->delete(route('assistant.orders.cancel', $order))->assertSessionHasErrors('package'); // já não está pendente
});

// ------------------------------------------------------------------ liberar e recusar

it('liberar o pedido coloca os tokens na carteira com a validade do pacote, uma vez só', function () {
    $owner = apkOwner();
    $order = app(PackageOrders::class)->request($owner, apkPackage(['tokens' => 2_000_000, 'validity_months' => 6]));
    $super = apkSuper();

    $this->actingAs($super)->post(route('admin.packages.approve', $order))->assertRedirect(route('admin.packages'));
    $this->actingAs($super)->post(route('admin.packages.approve', $order))->assertSessionHasErrors('package'); // segunda vez: nada

    $order->refresh();
    $wallet = app(TokenWallet::class);
    expect($order->status)->toBe('approved')->and($order->decided_by)->toBe($super->email)->and($order->decided_at)->not->toBeNull()
        ->and($wallet->balance($owner->tenant))->toBe(2_000_000);
    $entry = \App\Models\AssistantTokenEntry::query()->where('kind', 'purchase')->first();
    expect($entry->expires_on->toDateString())->toBe('2027-06-10')->and($entry->note)->toContain('Pacote P')->toContain($super->email);
});

it('recusar não mexe na carteira e guarda o motivo; pedido decidido não muda mais', function () {
    $owner = apkOwner();
    $order = app(PackageOrders::class)->request($owner, apkPackage());
    $super = apkSuper();

    $this->actingAs($super)->post(route('admin.packages.reject', $order), ['note' => 'Pagamento não identificado'])->assertRedirect(route('admin.packages'));

    $order->refresh();
    expect($order->status)->toBe('rejected')->and($order->note)->toBe('Pagamento não identificado')->and(app(TokenWallet::class)->balance($owner->tenant))->toBe(0);
    $this->actingAs($super)->post(route('admin.packages.approve', $order))->assertSessionHasErrors('package');
    expect($order->refresh()->status)->toBe('rejected');
});

it('liberar duas vezes ao mesmo tempo credita uma vez só', function () {
    $owner = apkOwner();
    $order = app(PackageOrders::class)->request($owner, apkPackage());

    $svc = app(PackageOrders::class);
    $svc->approve($order, 'a@x.test');
    expect(fn () => $svc->approve(AssistantPackageOrder::query()->find($order->id)->forceFill(['status' => 'pending']), 'b@x.test'))->toThrow(DomainException::class);

    expect(app(TokenWallet::class)->balance($owner->tenant))->toBe(1_000_000); // o claim é por UPDATE no banco, não pelo objeto em memória
});

// ------------------------------------------------------------------ receita no relatório

it('a receita dos pacotes liberados entra no relatório do mês', function () {
    apkPrices();
    $owner = apkOwner();
    $order = app(PackageOrders::class)->request($owner, apkPackage(['price_cents' => 4990, 'tokens' => 1_000_000]));
    app(PackageOrders::class)->approve($order, 'super@x.test');
    app(TokenWallet::class)->debit($owner->tenant, 'gpt-6-luna', 6000, 4000, 400, 'c1');

    $rows = app(TokenEconomics::class)->monthReport('2026-12');

    expect($rows)->toHaveCount(1)->and($rows[0]['revenue_brl'])->toBe(49.9)->and($rows[0]['cost_brl'])->toBe(0.002596);
    expect(app(TokenEconomics::class)->monthReport('2026-11'))->toBe([]);
});
