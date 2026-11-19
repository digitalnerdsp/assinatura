<?php

declare(strict_types=1);

use App\Assistant\AssistantSettings;
use App\Assistant\Channel\StatusProcessor;
use App\Assistant\Metering\MetaUsageLedger;
use App\Assistant\Metering\WhatsAppCredit;
use App\Assistant\Notifications\NotificationJanitor;
use App\Assistant\Notifications\TemplateStore;
use App\Jobs\SendWhatsAppNotification;
use App\Models\MetaMessageUsage;
use App\Models\Setting;
use App\Models\WhatsAppCreditEntry;
use App\Models\WhatsAppNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-12-10 12:00:00');
    app(AssistantSettings::class)->saveMeta(['access_token' => 'EAAB', 'phone_number_id' => 'PNID1', 'waba_id' => 'WABA1', 'graph_version' => 'v21.0']);
    Setting::put('assistant.markup_percent', '25');
    Setting::put('assistant.meta_rates', json_encode(['utility' => 0.04]));
    Http::fake(['graph.facebook.com/v21.0/PNID1/messages' => Http::response(['messages' => [['id' => 'wamid.R'.uniqid()]]])]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function wrStatus(string $id, string $phone): array
{
    return ['id' => $id, 'recipient_id' => $phone, 'status' => 'delivered', 'timestamp' => (string) now()->timestamp, 'pricing' => ['category' => 'utility', 'billable' => true]];
}

// 1 — verificar e enviar precisa contar o que já foi enviado e ainda não foi debitado

it('avisos já enviados e ainda não cobrados reservam o saldo: 3 avisos com saldo para 2', function () {
    $u = wnUser(['credit' => false]);
    wnApprove();
    app(WhatsAppCredit::class)->topUp($u->tenant, 0.10, 'pouco'); // cada aviso custa 0,05

    $r = [wnNotify($u, dedupe: 'r1'), wnNotify($u, dedupe: 'r2'), wnNotify($u, dedupe: 'r3')];

    expect($r)->toBe(['sent_queued', 'sent_queued', 'no_balance']);
    Http::assertSentCount(2);
});

it('quando a Meta informa a cobrança, a reserva vira débito e deixa de contar duas vezes', function () {
    $u = wnUser(['credit' => false]);
    wnApprove();
    app(WhatsAppCredit::class)->topUp($u->tenant, 0.10, 'pouco');
    wnNotify($u, dedupe: 'r1');
    $id = WhatsAppNotification::query()->value('wa_message_id');

    app(StatusProcessor::class)->handle(wrStatus($id, $u->whatsapp_phone));

    $c = app(WhatsAppCredit::class);
    expect($c->balance($u->tenant))->toBe(0.05)->and($c->reserved($u->tenant))->toBe(0.0)->and($c->available($u->tenant))->toBe(0.05);
});

// 2 — quem paga é a empresa que recebeu o aviso, não quem tem o número na hora do status

it('o status cobra a empresa que recebeu o aviso mesmo se o número foi desvinculado antes', function () {
    $u = wnUser();
    wnApprove();
    wnNotify($u, dedupe: 'x1');
    $id = WhatsAppNotification::query()->value('wa_message_id');
    $phone = $u->whatsapp_phone;
    $u->forceFill(['whatsapp_phone' => null, 'whatsapp_verified_at' => null])->save();

    app(StatusProcessor::class)->handle(wrStatus($id, $phone));

    $usage = MetaMessageUsage::query()->where('wa_message_id', $id)->first();
    expect($usage?->tenant_id)->toBe($u->tenant_id);
});

it('o status cobra a empresa do aviso mesmo se outra empresa vinculou o número depois', function () {
    $a = wnUser();
    wnApprove();
    wnNotify($a, dedupe: 'x2');
    $id = WhatsAppNotification::query()->value('wa_message_id');
    $phone = $a->whatsapp_phone;
    $a->forceFill(['whatsapp_phone' => null])->save();
    $b = wnUser();
    $b->forceFill(['whatsapp_phone' => $phone])->save();

    app(StatusProcessor::class)->handle(wrStatus($id, $phone));

    expect(MetaMessageUsage::query()->where('wa_message_id', $id)->value('tenant_id'))->toBe($a->tenant_id);
});

// 3 — débito que ficou para trás é refeito quando o aviso da Meta se repete

it('uso gravado sem débito é debitado quando a Meta repete o aviso, e uma vez só', function () {
    $u = wnUser(['credit' => false]);
    MetaMessageUsage::query()->create(['tenant_id' => $u->tenant_id, 'wa_message_id' => 'wamid.LOST', 'category' => 'utility', 'billable' => true, 'meta_amount' => 0.04, 'currency' => 'BRL', 'charged_amount' => 0.05, 'markup_percent' => 25, 'period' => '2026-12', 'occurred_at' => now()]);

    foreach ([1, 2] as $_) {
        app(MetaUsageLedger::class)->record($u->tenant, 'wamid.LOST', 'utility', true, 0.04, 'BRL', now());
    }

    expect(WhatsAppCreditEntry::query()->where('kind', 'usage')->count())->toBe(1)->and(app(WhatsAppCredit::class)->balance($u->tenant))->toBe(-0.05);
});

// 4 — o job confere de novo quem pode receber

it('o job não envia se o usuário foi desativado ou a empresa suspensa depois de enfileirar', function () {
    $u = wnUser();
    wnApprove();
    $mk = fn (string $d) => WhatsAppNotification::query()->create(['tenant_id' => $u->tenant_id, 'user_id' => $u->id, 'template_key' => 'signer_signed', 'dedupe_key' => $d, 'status' => 'queued', 'params' => ['Maria', 'Contrato'], 'reserved_amount' => 0.05]);

    $a = $mk('j1');
    $u->forceFill(['deactivated_at' => now()])->save();
    SendWhatsAppNotification::dispatchSync($a->id);
    expect($a->refresh()->status)->toBe('failed');

    $u->forceFill(['deactivated_at' => null])->save();
    $u->tenant->forceFill(['suspended_at' => now()])->save();
    $b = $mk('j2');
    SendWhatsAppNotification::dispatchSync($b->id);
    expect($b->refresh()->status)->toBe('failed');

    $u->tenant->forceFill(['suspended_at' => null])->save();
    $u->forceFill(['whatsapp_notifications' => []])->save();
    $c = $mk('j3');
    SendWhatsAppNotification::dispatchSync($c->id);
    expect($c->refresh()->status)->toBe('failed');

    Http::assertNothingSent();
});

// 5 — nada fica preso

it('uma falha inesperada no envio marca o aviso como falho (não fica preso em enviando)', function () {
    app(AssistantSettings::class)->saveMeta(['phone_number_id' => 'PNID3']);
    Http::fake(['graph.facebook.com/v21.0/PNID3/messages' => fn () => throw new RuntimeException('boom')]);
    $u = wnUser();
    wnApprove();

    wnNotify($u, dedupe: 'k1');

    $n = WhatsAppNotification::query()->first();
    expect($n->status)->toBe('failed')->and($n->reason)->not->toBeNull();
});

it('o faxineiro refaz o envio de aviso que ficou na fila e dá por interrompido o que ficou enviando', function () {
    $u = wnUser();
    wnApprove();
    $mk = fn (string $d, string $status, string $ago) => WhatsAppNotification::query()->create([
        'tenant_id' => $u->tenant_id, 'user_id' => $u->id, 'template_key' => 'signer_signed', 'dedupe_key' => $d, 'status' => $status,
        'params' => ['Maria', 'Contrato'], 'reserved_amount' => 0.05, 'created_at' => now()->sub($ago), 'updated_at' => now()->sub($ago),
    ]);
    $stuckQueued = $mk('f1', 'queued', '20 minutes');
    $stuckSending = $mk('f2', 'sending', '20 minutes');
    $fresh = $mk('f3', 'queued', '1 minute');

    $r = app(NotificationJanitor::class)->run();

    expect($stuckQueued->refresh()->status)->toBe('sent')->and($stuckSending->refresh()->status)->toBe('failed')->and($stuckSending->reason)->toContain('interrompido')
        ->and($fresh->refresh()->status)->toBe('queued')->and($r)->toBe(['requeued' => 1, 'interrupted' => 1]);
    Http::assertSentCount(1);
});

it('modelo pausado ou desativado pela Meta é desligado quando o envio volta com esse erro', function () {
    app(AssistantSettings::class)->saveMeta(['phone_number_id' => 'PNID2']);
    Http::fake(['graph.facebook.com/v21.0/PNID2/messages' => Http::response(['error' => ['message' => 'Template paused', 'code' => 132015]], 400)]);
    $u = wnUser();
    wnApprove();

    wnNotify($u, dedupe: 'p1');

    $t = app(TemplateStore::class)->find('signer_signed');
    expect($t->status)->toBe('pausado')->and($t->enabled)->toBeFalse();
});

// 8 — valores em dinheiro

it('dinheiro: "1.000" é mil reais, texto inválido no limite é erro e valor gigante é recusado', function () {
    $u = wnUser();
    $super = wbSuper();

    $this->actingAs($super)->post(route('admin.notifications.credit'), ['tenant_id' => $u->tenant_id, 'kind' => 'topup', 'amount' => '1.000', 'note' => 'Pix'])->assertRedirect();
    expect(app(WhatsAppCredit::class)->balance($u->tenant))->toBe(1005.0); // 5 da recarga do helper + 1.000

    $this->actingAs($super)->put(route('admin.notifications.billing', $u->tenant), ['wa_billing_mode' => 'postpaid', 'wa_credit_limit' => 'abc'])->assertSessionHasErrors('wa_credit_limit');
    $this->actingAs($super)->post(route('admin.notifications.credit'), ['tenant_id' => $u->tenant_id, 'kind' => 'topup', 'amount' => '99999999999', 'note' => 'x'])->assertSessionHasErrors('amount');
    expect($u->tenant->refresh()->wa_billing_mode)->toBe('prepaid');
});

// 9 — estado dos modelos

it('só rascunho ou rejeitado vai para aprovação, e o nome do modelo não repete entre situações', function () {
    Http::fake(['graph.facebook.com/v21.0/WABA1/message_templates' => Http::response(['id' => 'T1', 'status' => 'PENDING'])]);
    $store = app(TemplateStore::class);
    $t = $store->find('signer_signed');
    $t->forceFill(['status' => 'aprovado'])->save();

    expect(fn () => $store->submit($t->refresh()))->toThrow(\App\Assistant\Channel\MetaException::class);

    $super = wbSuper();
    $this->actingAs($super)->put(route('admin.notifications.update', 'signer_refused'), ['meta_name' => $t->meta_name, 'body' => 'Atenção, {{1}} recusou o documento {{2}} agora.', 'language' => 'pt_BR'])->assertSessionHasErrors('meta_name');
});

// 6 — desempenho: a checagem da empresa da plataforma não carrega todos os usuários

it('isPlatformOwned reconhece super administrador por papel e por e-mail da configuração, sem carregar usuários', function () {
    $t = \App\Models\Tenant::create(['name' => 'P']);
    $u = \App\Models\User::create(['tenant_id' => $t->id, 'name' => 'S', 'email' => 'raiz@wr.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    expect($t->isPlatformOwned())->toBeFalse();

    config(['platform.super_admins' => ['raiz@wr.test']]);
    expect($t->isPlatformOwned())->toBeTrue();

    config(['platform.super_admins' => []]);
    $u->forceFill(['platform_role' => 'super_admin'])->save();
    expect($t->isPlatformOwned())->toBeTrue();

    $u->forceFill(['platform_role' => 'admin'])->save(); // admin comum não conta
    expect($t->isPlatformOwned())->toBeFalse();
});
