<?php

declare(strict_types=1);

use App\Assistant\AssistantSettings;
use App\Assistant\Metering\WhatsAppCredit;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\Entitlements;
use App\Plans\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function wbSuper(): User
{
    $u = User::create(['tenant_id' => Tenant::create(['name' => 'Plataforma '.uniqid()])->id, 'name' => 'Super', 'email' => 'super'.uniqid().'@wb.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['platform_role' => 'super_admin'])->save();

    return $u;
}

function wbOwner(array $limits = []): User
{
    static $n = 0;
    $n++;
    $plan = Plan::create(['name' => 'WB'.$n, 'slug' => 'wb-'.$n, 'active' => true, 'features' => ['assistant' => true], 'limits' => $limits]);
    $tenant = Tenant::create(['name' => 'Empresa WB '.$n]);
    app(PlanService::class)->assign($tenant, $plan);
    $u = User::create(['tenant_id' => $tenant->id, 'name' => 'Dono', 'email' => "dono{$n}@wb.test", 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['whatsapp_phone' => '5511977'.str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'whatsapp_verified_at' => now()])->save();

    return $u->fresh();
}

// ------------------------------------------------------------------ valor global no plano

it('o plano define o limite pós-pago dos avisos; em branco ou 0 é pré-pago', function () {
    $this->actingAs(wbSuper())->get(route('admin.plans.create'))->assertOk()->assertSee('Limite pós-pago dos avisos do WhatsApp');

    expect(app(WhatsAppCredit::class)->isPostpaid(wbOwner()->tenant))->toBeFalse();

    $post = wbOwner(['wa_credit_limit_cents' => 5000]);
    $c = app(WhatsAppCredit::class);
    expect($c->isPostpaid($post->tenant))->toBeTrue()->and($c->limit($post->tenant))->toBe(50.0);

    expect($c->isPostpaid(wbOwner(['wa_credit_limit_cents' => 0])->tenant))->toBeFalse();
});

it('o formulário do plano guarda o valor em reais como centavos', function () {
    $super = wbSuper();
    $form = app(\App\Plans\PlanForm::class);

    $attrs = $form->plan(['limits' => ['wa_credit_limit' => '50,00', 'users' => '3']])['attrs'];
    expect($attrs['limits'])->toBe(['users' => 3, 'wa_credit_limit_cents' => 5000]);

    expect($form->plan(['limits' => ['wa_credit_limit' => '']])['attrs']['limits'])->toBe([]);
    expect(fn () => $form->plan(['limits' => ['wa_credit_limit' => 'abc']]))->toThrow(\App\Envelopes\Exceptions\FlowException::class);
});

// ------------------------------------------------------------------ personalizado por empresa (assinatura)

it('a empresa pode ter um valor próprio que vale mais que o do plano, e 0 força pré-pago', function () {
    $owner = wbOwner(['wa_credit_limit_cents' => 5000]);
    $c = app(WhatsAppCredit::class);

    $overrides = app(\App\Plans\PlanForm::class)->overrides(['limits' => ['wa_credit_limit' => '120,00']]);
    expect($overrides['limits']['wa_credit_limit_cents'])->toBe(12000);
    app(PlanService::class)->subscriptionFor($owner->tenant)->forceFill(['overrides' => $overrides])->save();
    expect($c->limit($owner->tenant))->toBe(120.0);

    $zero = app(\App\Plans\PlanForm::class)->overrides(['limits' => ['wa_credit_limit' => '0']]);
    app(PlanService::class)->subscriptionFor($owner->tenant)->forceFill(['overrides' => $zero])->save();
    expect($c->isPostpaid($owner->tenant))->toBeFalse();

    $blank = app(\App\Plans\PlanForm::class)->overrides(['limits' => ['wa_credit_limit' => '']]);
    app(PlanService::class)->subscriptionFor($owner->tenant)->forceFill(['overrides' => $blank])->save();
    expect($c->limit($owner->tenant))->toBe(50.0); // volta ao do plano
});

it('a página da assinatura mostra o modo, o saldo e o campo de sobrescrita', function () {
    $owner = wbOwner(['wa_credit_limit_cents' => 5000]);
    app(WhatsAppCredit::class)->topUp($owner->tenant, 12.5, 'Pix');

    $this->actingAs(wbSuper())->get(route('admin.subscriptions.show', $owner->tenant))->assertOk()
        ->assertSee('Saldo dos avisos no WhatsApp')->assertSee('pós-pago, limite de R$ 50,00')->assertSee('12,50')->assertSee('Limite pós-pago dos avisos do WhatsApp (R$)');
});

it('salvar a assinatura com o limite da empresa grava a sobrescrita', function () {
    $owner = wbOwner();
    $sub = app(PlanService::class)->subscriptionFor($owner->tenant);

    $this->actingAs(wbSuper())->put(route('admin.subscriptions.update', $owner->tenant), ['plan_id' => $sub->plan_id, 'status' => 'active', 'limits' => ['wa_credit_limit' => '30,00']])->assertRedirect();

    expect(app(WhatsAppCredit::class)->limit($owner->tenant->refresh()))->toBe(30.0);
});

// ------------------------------------------------------------------ lançamentos (na página da empresa)

it('lança recarga, ajuste e quitação com nota, e recusa valor inválido', function () {
    $owner = wbOwner();
    $super = wbSuper();
    $c = app(WhatsAppCredit::class);
    $url = route('admin.subscriptions.wa-credit', $owner->tenant);

    $this->actingAs($super)->post($url, ['kind' => 'topup', 'amount' => '20,00', 'note' => 'Pix 1'])->assertRedirect();
    $this->actingAs($super)->post($url, ['kind' => 'adjust', 'amount' => '-2,5', 'note' => 'Correção'])->assertRedirect();
    expect($c->balance($owner->tenant))->toBe(17.5);

    $this->actingAs($super)->post($url, ['kind' => 'settle', 'amount' => '1', 'note' => 'Mensalidade'])->assertRedirect();
    expect($c->balance($owner->tenant))->toBe(18.5);

    $this->actingAs($super)->post($url, ['kind' => 'topup', 'amount' => '0', 'note' => 'x'])->assertSessionHasErrors('amount');
    $this->actingAs($super)->post($url, ['kind' => 'topup', 'amount' => '5', 'note' => ''])->assertSessionHasErrors('note');
    expect($c->entries($owner->tenant)->first()->created_by)->toBe($super->email);
});

it('só o super administrador lança no saldo', function () {
    $owner = wbOwner();
    $admin = User::create(['tenant_id' => Tenant::create(['name' => 'Op'])->id, 'name' => 'Admin', 'email' => 'admin@wb.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $admin->forceFill(['platform_role' => 'admin'])->save();
    $url = route('admin.subscriptions.wa-credit', $owner->tenant);

    $this->actingAs($owner)->post($url, ['kind' => 'topup', 'amount' => '9', 'note' => 'x'])->assertNotFound();
    $this->actingAs($admin)->post($url, ['kind' => 'topup', 'amount' => '9', 'note' => 'x'])->assertForbidden();
    expect(app(WhatsAppCredit::class)->balance($owner->tenant))->toBe(0.0);
});

it('a tela de avisos não lista mais empresas, só os modelos e os dias de parado', function () {
    $owner = wbOwner();

    $this->actingAs(wbSuper())->get(route('admin.notifications'))->assertOk()->assertDontSee($owner->tenant->name)->assertSee('Documento parado')->assertSee('definida no');
});

it('salva os dias para considerar um documento parado', function () {
    $this->actingAs(wbSuper())->put(route('admin.notifications.settings'), ['stalled_days' => '7'])->assertRedirect();
    expect(app(AssistantSettings::class)->stalledDays())->toBe(7);

    $this->actingAs(wbSuper())->put(route('admin.notifications.settings'), ['stalled_days' => '0'])->assertSessionHasErrors('stalled_days');
    $this->actingAs(wbSuper())->put(route('admin.notifications.settings'), ['stalled_days' => '120'])->assertSessionHasErrors('stalled_days');
});

// ------------------------------------------------------------------ perfil e painel da empresa

it('o usuário escolhe no perfil quais avisos quer receber', function () {
    $u = wbOwner();

    $this->actingAs($u)->get(route('profile.edit'))->assertOk()->assertSee('Avisos no WhatsApp')->assertSee('Documento assinado por todos');

    $this->actingAs($u)->put(route('profile.whatsapp.notices'), ['notices' => ['document_completed', 'signer_refused']])->assertRedirect(route('profile.edit'));
    expect($u->refresh()->whatsapp_notifications)->toBe(['document_completed', 'signer_refused'])
        ->and($u->wantsWhatsAppNotice('signer_signed'))->toBeFalse()->and($u->wantsWhatsAppNotice('document_completed'))->toBeTrue();

    $this->actingAs($u)->put(route('profile.whatsapp.notices'), ['notices' => ['inventado']])->assertSessionHasErrors('notices.0');
    $this->actingAs($u)->put(route('profile.whatsapp.notices'), [])->assertRedirect();
    expect($u->refresh()->whatsapp_notifications)->toBe([]); // desmarcou tudo: nenhum aviso
});

it('o painel da empresa mostra saldo, modo de cobrança e aviso de saldo baixo', function () {
    Setting::put('assistant.meta_rates', json_encode(['utility' => 0.04]));
    $u = wbOwner();

    $this->actingAs($u)->get(route('assistant.index'))->assertOk()->assertSee('Saldo para avisos no WhatsApp')->assertSee('Sem saldo')->assertSee('pré-pago');

    app(WhatsAppCredit::class)->topUp($u->tenant, 30.0, 'Pix');
    $this->actingAs($u)->get(route('assistant.index'))->assertOk()->assertSee('30,00')->assertDontSee('Sem saldo');
});
