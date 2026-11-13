<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\UsageEntry;
use App\Models\User;
use App\Plans\Entitlements;
use App\Plans\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['platform.super_admins' => ['raiz@plataforma.com']]));

function paUser(string $email, ?string $platformRole = null, ?Tenant $tenant = null, string $role = 'owner'): User
{
    $u = User::create(['tenant_id' => ($tenant ?? Tenant::create(['name' => 'Empresa '.$email]))->id, 'name' => 'Pessoa '.$email, 'email' => $email, 'password' => 'senha-muito-forte', 'role' => $role]);
    if ($platformRole) {
        $u->forceFill(['platform_role' => $platformRole])->save();
    }

    return $u->fresh();
}

function planForm(array $over = []): array
{
    return $over + [
        'name' => 'Profissional', 'description' => 'Para equipes', 'active' => 1, 'is_public' => 1, 'position' => 2,
        'limits' => ['documents' => '100', 'users' => '5', 'storage_mb' => '10240', 'max_pdf_mb' => '20'],
        'trial' => ['days' => '14', 'requires_payment' => 0, 'on_end' => 'block', 'limits' => ['documents' => '5']],
        'consumption' => ['when' => 'send', 'balance' => 'both', 'on_empty' => 'overage', 'overage' => '2,50', 'refund_unsigned' => 1, 'cap' => ''],
        'features' => ['api' => 1, 'webhooks' => 1, 'templates' => 1, 'reports' => 1],
        'prices' => [
            'month' => ['amount' => '49,90', 'discount_label' => '', 'active' => 1],
            'quarter' => ['amount' => '134,73', 'discount_label' => '10% off', 'active' => 1],
            'semester' => ['amount' => '', 'discount_label' => '', 'active' => 0],
            'year' => ['amount' => '479,00', 'discount_label' => '2 meses grátis', 'active' => 1],
        ],
    ];
}

it('super admin cria um plano completo: limites, teste, consumo, recursos e preços em centavos', function () {
    $this->actingAs(paUser('raiz@plataforma.com'));

    $this->post('/admin/planos', planForm())->assertRedirect(route('admin.plans.index'))->assertSessionHasNoErrors();

    $p = Plan::where('slug', 'profissional')->firstOrFail();
    expect($p->limits)->toEqual(['documents' => 100, 'users' => 5, 'storage_mb' => 10240, 'max_pdf_mb' => 20])
        ->and($p->trial)->toMatchArray(['days' => 14, 'on_end' => 'block', 'requires_payment' => false])
        ->and($p->trial['limits'])->toEqual(['documents' => 5])
        ->and($p->consumption)->toMatchArray(['when' => 'send', 'balance' => 'both', 'on_empty' => 'overage', 'overage_cents' => 250, 'refund_unsigned' => true])
        ->and($p->consumption)->not->toHaveKey('cap')
        ->and($p->features['api'])->toBeTrue()->and($p->features['selfie'])->toBeFalse()->and(array_keys($p->features))->toEqualCanonicalizing(Plan::FEATURES);

    $prices = $p->prices()->get()->keyBy('period');
    expect($prices['month']->amount_cents)->toBe(4990)->and($prices['quarter']->amount_cents)->toBe(13473)
        ->and($prices['quarter']->discount_label)->toBe('10% off')->and($prices['year']->amount_cents)->toBe(47900)
        ->and($prices->has('semester'))->toBeFalse();

    $this->get('/admin/planos')->assertOk()->assertSee('Profissional')->assertSee('R$ 49,90');
});

it('slug é gerado e nunca repete; Legado é reservado', function () {
    $this->actingAs(paUser('raiz@plataforma.com'));
    $this->post('/admin/planos', planForm(['name' => 'Básico']))->assertSessionHasNoErrors();
    $this->post('/admin/planos', planForm(['name' => 'Básico']))->assertSessionHasNoErrors();
    $this->post('/admin/planos', planForm(['name' => 'Legado']))->assertSessionHasNoErrors();

    expect(Plan::pluck('slug')->all())->toEqualCanonicalizing(['basico', 'basico-2', 'legado', 'legado-2']);
});

it('valores inválidos são recusados com mensagem e nada é gravado', function () {
    $this->actingAs(paUser('raiz@plataforma.com'));

    $this->post('/admin/planos', planForm(['limits' => ['documents' => '-5']]))->assertSessionHasErrors('plan');
    $this->post('/admin/planos', planForm(['limits' => ['users' => '1,5']]))->assertSessionHasErrors('plan');
    $this->post('/admin/planos', planForm(['consumption' => ['when' => 'ontem']]))->assertSessionHasErrors('plan');
    $this->post('/admin/planos', planForm(['trial' => ['days' => '500', 'on_end' => 'block']]))->assertSessionHasErrors();
    $this->post('/admin/planos', planForm(['prices' => ['month' => ['amount' => 'abc', 'active' => 1]]]))->assertSessionHasErrors('plan');
    $this->post('/admin/planos', planForm(['prices' => ['month' => ['amount' => '-3,00', 'active' => 1]]]))->assertSessionHasErrors('plan');
    $this->post('/admin/planos', planForm(['name' => '']))->assertSessionHasErrors('name');

    expect(Plan::where('slug', '!=', 'legado')->count())->toBe(0);
});

it('só super admin mexe em planos; admin comum vê assinaturas e usuário comum não vê nada', function () {
    $this->actingAs(paUser('admin@plataforma.com', 'admin'));
    $this->get('/admin/planos')->assertForbidden();
    $this->post('/admin/planos', planForm())->assertForbidden();
    $this->get('/admin/assinaturas')->assertOk();

    $this->actingAs(paUser('comum@cliente.com'));
    $this->get('/admin/planos')->assertNotFound();
    $this->get('/admin/assinaturas')->assertNotFound();
});

it('edita, escolhe o padrão, e plano em uso só é desativado, nunca apagado', function () {
    $this->actingAs(paUser('raiz@plataforma.com'));
    $this->post('/admin/planos', planForm(['name' => 'A']))->assertSessionHasNoErrors();
    $this->post('/admin/planos', planForm(['name' => 'B']))->assertSessionHasNoErrors();
    $a = Plan::where('slug', 'a')->first();
    $b = Plan::where('slug', 'b')->first();

    $this->put('/admin/planos/'.$a->id, planForm(['name' => 'A Plus', 'limits' => ['documents' => '999'], 'prices' => ['month' => ['amount' => '10,00', 'active' => 1]]]))->assertSessionHasNoErrors();
    expect($a->fresh()->name)->toBe('A Plus')->and($a->fresh()->limits['documents'])->toBe(999)->and($a->prices()->count())->toBe(1);

    $this->post('/admin/planos/'.$a->id.'/padrao')->assertRedirect();
    $this->post('/admin/planos/'.$b->id.'/padrao')->assertRedirect();
    expect($a->fresh()->is_default)->toBeFalse()->and($b->fresh()->is_default)->toBeTrue();

    app(PlanService::class)->assign(Tenant::create(['name' => 'Cliente']), $a);
    $this->delete('/admin/planos/'.$a->id)->assertRedirect()->assertSessionHas('status');
    expect($a->fresh())->not->toBeNull()->and($a->fresh()->active)->toBeFalse();

    $this->delete('/admin/planos/'.$b->id)->assertSessionHasErrors('plan'); // o padrão não se apaga
    expect(Plan::find($b->id))->not->toBeNull();
    $this->post('/admin/planos', planForm(['name' => 'C']))->assertSessionHasNoErrors();
    $this->post('/admin/planos/'.Plan::where('slug', 'c')->value('id').'/padrao')->assertRedirect();
    $this->delete('/admin/planos/'.$b->id)->assertRedirect();
    expect(Plan::find($b->id))->toBeNull(); // sem empresas e já não é o padrão: apaga

    $legacy = app(PlanService::class)->legacy();
    $this->delete('/admin/planos/'.$legacy->id)->assertSessionHasErrors('plan');
    $this->put('/admin/planos/'.$legacy->id, planForm(['limits' => ['documents' => '1']]))->assertSessionHasErrors('plan');
    expect($legacy->fresh()->limits)->toBe([]);
});

it('o admin troca o plano da empresa e define sobrescritas que valem na hora', function () {
    $cliente = Tenant::create(['name' => 'Cliente Ltda']);
    $plan = Plan::create(['name' => 'P', 'slug' => 'p', 'limits' => ['documents' => 10, 'users' => 2, 'storage_mb' => 100], 'features' => array_fill_keys(Plan::FEATURES, true)]);
    $outro = Plan::create(['name' => 'Outro', 'slug' => 'outro', 'limits' => ['documents' => 500]]);
    app(PlanService::class)->assign($cliente, $plan);
    $this->actingAs(paUser('admin@plataforma.com', 'admin'));

    $this->get('/admin/assinaturas')->assertOk()->assertSee('Cliente Ltda')->assertSee('P');
    $this->get('/admin/assinaturas/'.$cliente->id)->assertOk()->assertSee('Cliente Ltda');

    $this->put('/admin/assinaturas/'.$cliente->id, [
        'plan_id' => $outro->id, 'status' => 'active', 'billing_exempt' => 1, 'notes' => 'cliente amigo',
        'limits' => ['documents' => '', 'users' => '', 'storage_mb' => '200000', 'max_pdf_mb' => ''],
        'unlimited' => ['users' => 1],
        'features' => ['api' => 'off', 'webhooks' => 'inherit', 'white_label' => 'on'],
        'consumption' => ['quota_amount' => '', 'on_empty' => 'overage', 'overage' => '1,00'],
        'price' => '99,90',
    ])->assertSessionHasNoErrors();

    $s = $cliente->fresh()->subscription;
    expect($s->plan_id)->toBe($outro->id)->and($s->billing_exempt)->toBeTrue()->and($s->notes)->toBe('cliente amigo');
    $eff = app(PlanService::class)->effective($s->load('plan'));
    expect($eff['limits'])->toEqual(['documents' => 500, 'users' => null, 'storage_mb' => 200000])
        ->and($eff['features']['api'])->toBeFalse()->and($eff['features']['white_label'])->toBeTrue()
        ->and($eff['consumption']['on_empty'])->toBe('overage')->and($eff['consumption']['overage_cents'])->toBe(100)
        ->and($s->overrides['price_cents'])->toBe(9990);
    expect(Entitlements::for($cliente->fresh())->limit('users'))->toBeNull();
});

it('lança crédito manual no extrato, sem deixar o saldo ficar negativo', function () {
    $cliente = Tenant::create(['name' => 'Cliente']);
    app(PlanService::class)->assign($cliente, app(PlanService::class)->legacy());
    $this->actingAs(paUser('admin@plataforma.com', 'admin'));

    $this->post('/admin/assinaturas/'.$cliente->id.'/creditos', ['delta' => '50', 'note' => 'PIX recebido fora do sistema'])->assertSessionHasNoErrors();
    expect($cliente->fresh()->subscription->credits_balance)->toBe(50);
    $e = UsageEntry::firstOrFail();
    expect($e->kind)->toBe('adjust')->and($e->delta)->toBe(50)->and($e->balance_after)->toBe(50)->and($e->note)->toContain('PIX');

    $this->post('/admin/assinaturas/'.$cliente->id.'/creditos', ['delta' => '-80', 'note' => 'erro'])->assertSessionHasErrors('delta');
    $this->post('/admin/assinaturas/'.$cliente->id.'/creditos', ['delta' => '0', 'note' => 'x'])->assertSessionHasErrors('delta');
    $this->post('/admin/assinaturas/'.$cliente->id.'/creditos', ['delta' => '5', 'note' => ''])->assertSessionHasErrors('note');
    expect($cliente->fresh()->subscription->credits_balance)->toBe(50)->and(UsageEntry::count())->toBe(1);
});

it('a empresa vê só o próprio plano, uso e extrato; membro não vê', function () {
    $a = Tenant::create(['name' => 'Alfa']);
    $b = Tenant::create(['name' => 'Beta']);
    $plan = Plan::create(['name' => 'Plano Ouro', 'slug' => 'ouro', 'limits' => ['documents' => 10, 'users' => 3, 'storage_mb' => 1000], 'features' => array_fill_keys(Plan::FEATURES, true)]);
    app(PlanService::class)->assign($a, $plan);
    app(PlanService::class)->assign($b, $plan);
    UsageEntry::create(['tenant_id' => $a->id, 'kind' => 'adjust', 'delta' => 5, 'balance_after' => 5, 'note' => 'Crédito da Alfa', 'occurred_at' => now()]);
    UsageEntry::create(['tenant_id' => $b->id, 'kind' => 'adjust', 'delta' => 9, 'balance_after' => 9, 'note' => 'Crédito da Beta', 'occurred_at' => now()]);

    $this->actingAs(paUser('dono@alfa.com', null, $a));
    $this->get('/configuracoes/plano')->assertOk()->assertSee('Plano Ouro')->assertSee('Documentos')->assertSee('Usuários')->assertSee('Armazenamento')
        ->assertSee('Crédito da Alfa')->assertDontSee('Crédito da Beta');

    $this->actingAs(paUser('colega@alfa.com', null, $a, 'member'))->get('/configuracoes/plano')->assertForbidden();
});
