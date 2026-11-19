<?php

declare(strict_types=1);

use App\Assistant\AssistantSettings;
use App\Assistant\Metering\WhatsAppCredit;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function wbSuper(): User
{
    $u = User::create(['tenant_id' => Tenant::create(['name' => 'Plataforma '.uniqid()])->id, 'name' => 'Super', 'email' => 'super'.uniqid().'@wb.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['platform_role' => 'super_admin'])->save();

    return $u;
}

function wbOwner(): User
{
    static $n = 0;
    $n++;
    $plan = Plan::create(['name' => 'WB'.$n, 'slug' => 'wb-'.$n, 'active' => true, 'features' => ['assistant' => true]]);
    $tenant = Tenant::create(['name' => 'Empresa WB '.$n]);
    app(PlanService::class)->assign($tenant, $plan);
    $u = User::create(['tenant_id' => $tenant->id, 'name' => 'Dono', 'email' => "dono{$n}@wb.test", 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['whatsapp_phone' => '5511977'.str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'whatsapp_verified_at' => now()])->save();

    return $u->fresh();
}

it('a tela de avisos lista as empresas com saldo e modo de cobrança', function () {
    $owner = wbOwner();
    app(WhatsAppCredit::class)->topUp($owner->tenant, 12.5, 'Pix');

    $this->actingAs(wbSuper())->get(route('admin.notifications'))->assertOk()->assertSee($owner->tenant->name)->assertSee('12,50');
});

it('o super administrador muda o modo e o limite de uma empresa', function () {
    $owner = wbOwner();

    $this->actingAs(wbSuper())->put(route('admin.notifications.billing', $owner->tenant), ['wa_billing_mode' => 'postpaid', 'wa_credit_limit' => '50,00'])->assertRedirect();

    $t = $owner->tenant->refresh();
    expect($t->wa_billing_mode)->toBe('postpaid')->and((float) $t->wa_credit_limit)->toBe(50.0);

    $this->actingAs(wbSuper())->put(route('admin.notifications.billing', $owner->tenant), ['wa_billing_mode' => 'qualquer', 'wa_credit_limit' => '1'])->assertSessionHasErrors('wa_billing_mode');
    $this->actingAs(wbSuper())->put(route('admin.notifications.billing', $owner->tenant), ['wa_billing_mode' => 'postpaid', 'wa_credit_limit' => '-5'])->assertSessionHasErrors('wa_credit_limit');
});

it('lança recarga, ajuste e quitação com nota, e recusa valor inválido', function () {
    $owner = wbOwner();
    $super = wbSuper();
    $c = app(WhatsAppCredit::class);

    $this->actingAs($super)->post(route('admin.notifications.credit'), ['tenant_id' => $owner->tenant_id, 'kind' => 'topup', 'amount' => '20,00', 'note' => 'Pix 1'])->assertRedirect();
    $this->actingAs($super)->post(route('admin.notifications.credit'), ['tenant_id' => $owner->tenant_id, 'kind' => 'adjust', 'amount' => '-2,5', 'note' => 'Correção'])->assertRedirect();
    expect($c->balance($owner->tenant))->toBe(17.5);

    $this->actingAs($super)->post(route('admin.notifications.credit'), ['tenant_id' => $owner->tenant_id, 'kind' => 'settle', 'amount' => '1', 'note' => 'Mensalidade'])->assertRedirect();
    expect($c->balance($owner->tenant))->toBe(18.5);

    $this->actingAs($super)->post(route('admin.notifications.credit'), ['tenant_id' => $owner->tenant_id, 'kind' => 'topup', 'amount' => '0', 'note' => 'x'])->assertSessionHasErrors('amount');
    $this->actingAs($super)->post(route('admin.notifications.credit'), ['tenant_id' => $owner->tenant_id, 'kind' => 'topup', 'amount' => '5', 'note' => ''])->assertSessionHasErrors('note');
    expect($c->entries($owner->tenant)->first()->created_by)->toBe($super->email);
});

it('só o super administrador mexe em cobrança', function () {
    $owner = wbOwner();

    $this->actingAs($owner)->put(route('admin.notifications.billing', $owner->tenant), ['wa_billing_mode' => 'postpaid', 'wa_credit_limit' => '9'])->assertNotFound();
    $this->actingAs($owner)->post(route('admin.notifications.credit'), ['tenant_id' => $owner->tenant_id, 'kind' => 'topup', 'amount' => '9', 'note' => 'x'])->assertNotFound();
    expect($owner->tenant->refresh()->wa_billing_mode)->toBe('prepaid');
});

it('salva os dias para considerar um documento parado', function () {
    $this->actingAs(wbSuper())->put(route('admin.notifications.settings'), ['stalled_days' => '7'])->assertRedirect();
    expect(app(AssistantSettings::class)->stalledDays())->toBe(7);

    $this->actingAs(wbSuper())->put(route('admin.notifications.settings'), ['stalled_days' => '0'])->assertSessionHasErrors('stalled_days');
    $this->actingAs(wbSuper())->put(route('admin.notifications.settings'), ['stalled_days' => '120'])->assertSessionHasErrors('stalled_days');
});

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

    $this->actingAs($u)->get(route('assistant.index'))->assertOk()->assertSee('Saldo para avisos no WhatsApp')->assertSee('Sem saldo');

    app(WhatsAppCredit::class)->topUp($u->tenant, 30.0, 'Pix');
    $this->actingAs($u)->get(route('assistant.index'))->assertOk()->assertSee('30,00')->assertDontSee('Sem saldo');
});
