<?php

declare(strict_types=1);

use App\Assistant\AssistantSettings;
use App\Assistant\Metering\WhatsAppCredit;
use App\Assistant\Notifications\StalledDocuments;
use App\Assistant\Notifications\TemplateStore;
use App\Assistant\Notifications\WhatsAppNotifier;
use App\Jobs\SendWhatsAppNotification;
use App\Models\Envelope;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Plans\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-12-10 12:00:00');
    app(AssistantSettings::class)->saveMeta(['access_token' => 'EAAB', 'phone_number_id' => 'PNID1', 'waba_id' => 'WABA1', 'graph_version' => 'v21.0']);
    Setting::put('assistant.markup_percent', '25');
    Setting::put('assistant.meta_rates', json_encode(['utility' => 0.04, 'marketing' => 0.35]));
    Http::fake(['graph.facebook.com/v21.0/PNID1/messages' => Http::response(['messages' => [['id' => 'wamid.N1']]])]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Usuário vinculado, de empresa com o recurso, com saldo e o modelo da situação aprovado. */
function wnUser(array $o = []): User
{
    static $n = 0;
    $n++;
    $plan = Plan::create(['name' => 'WN'.$n, 'slug' => 'wn-'.$n, 'active' => true, 'features' => ['assistant' => $o['feature'] ?? true]]);
    $tenant = Tenant::create(['name' => 'Empresa WN '.$n]);
    app(PlanService::class)->assign($tenant, $plan);
    $u = User::create(['tenant_id' => $tenant->id, 'name' => 'Ana', 'email' => "ana{$n}@wn.test", 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['whatsapp_phone' => '551198'.str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'whatsapp_verified_at' => now()])->save();
    if ($o['credit'] ?? true) {
        app(WhatsAppCredit::class)->topUp($tenant, 5.0, 'teste');
    }

    return $u->fresh();
}

function wnApprove(?string $key = null): void
{
    $store = app(TemplateStore::class);
    foreach ($key === null ? array_keys(\App\Assistant\Notifications\NotificationCatalog::all()) : [$key] as $k) {
        $store->find($k)->forceFill(['status' => 'aprovado', 'enabled' => true])->save();
    }
}

function wnNotify(User $u, string $key = 'signer_signed', string $dedupe = 'd1', array $params = ['Maria', 'Contrato X']): string
{
    return app(WhatsAppNotifier::class)->notify($u, $key, $params, $dedupe);
}

it('envia o modelo aprovado com as variáveis e registra o aviso', function () {
    $u = wnUser();
    wnApprove();

    expect(wnNotify($u))->toBe('sent_queued');

    $n = WhatsAppNotification::query()->first();
    expect($n->status)->toBe('sent')->and($n->wa_message_id)->toBe('wamid.N1')->and($n->user_id)->toBe($u->id)->and($n->params)->toBeNull();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'PNID1/messages') && $r['template']['name'] === 'assinaja_assinou'
        && $r['to'] === $u->whatsapp_phone && $r['template']['components'][0]['parameters'][1]['text'] === 'Contrato X');
});

it('o mesmo aviso (mesma chave) nunca sai duas vezes', function () {
    $u = wnUser();
    wnApprove();

    expect(wnNotify($u))->toBe('sent_queued')->and(wnNotify($u))->toBe('duplicate');

    expect(WhatsAppNotification::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('o job reenviado de um aviso já tratado não envia de novo', function () {
    $u = wnUser();
    wnApprove();
    wnNotify($u);
    $id = WhatsAppNotification::query()->value('id');

    SendWhatsAppNotification::dispatchSync($id);

    Http::assertSentCount(1);
});

it('cada motivo de não enviar devolve o seu código e não manda nada', function () {
    wnApprove();
    $ok = wnUser();

    // sem vínculo
    $a = wnUser();
    $a->forceFill(['whatsapp_phone' => null, 'whatsapp_verified_at' => null])->save();
    expect(wnNotify($a->fresh(), dedupe: 'a'))->toBe('not_linked');

    // empresa sem o recurso
    expect(wnNotify(wnUser(['feature' => false]), dedupe: 'b'))->toBe('no_feature');

    // usuário desativado
    $d = wnUser();
    $d->forceFill(['deactivated_at' => now()])->save();
    expect(wnNotify($d->fresh(), dedupe: 'c'))->toBe('no_user')->and(app(WhatsAppNotifier::class)->notify(null, "signer_signed", [], "c2"))->toBe('no_user');

    // preferência desligada
    $p = wnUser();
    $p->forceFill(['whatsapp_notifications' => ['document_completed']])->save();
    expect(wnNotify($p->fresh(), dedupe: 'd'))->toBe('pref_off');

    // sem saldo
    expect(wnNotify(wnUser(['credit' => false]), dedupe: 'e'))->toBe('no_balance');

    // sem preço da categoria
    Setting::put('assistant.meta_rates', json_encode(['marketing' => 0.35]));
    expect(wnNotify($ok, dedupe: 'f'))->toBe('no_price');
    Setting::put('assistant.meta_rates', json_encode(['utility' => 0.04]));

    // modelo desligado
    app(TemplateStore::class)->find('signer_signed')->forceFill(['enabled' => false])->save();
    expect(wnNotify($ok, dedupe: 'g'))->toBe('no_template');

    Http::assertNothingSent();
    expect(WhatsAppNotification::query()->count())->toBe(0);
});

it('super administrador e a empresa dele não recebem avisos de plataforma', function () {
    wnApprove();
    $u = wnUser();
    $u->forceFill(['platform_role' => 'super_admin'])->save();

    expect(wnNotify($u->fresh()))->toBe('platform');
    Http::assertNothingSent();
});

it('empresa suspensa não recebe', function () {
    wnApprove();
    $u = wnUser();
    $u->tenant->forceFill(['suspended_at' => now()])->save();

    expect(wnNotify($u->fresh()))->toBe('platform');
});

it('o pós-pago dentro do limite recebe mesmo sem saldo; acima do limite não', function () {
    wnApprove();
    $u = wnUser(['credit' => false]);
    wcPostpaid($u->tenant, 0.04);

    expect(wnNotify($u->fresh(), dedupe: 'p1'))->toBe('no_balance'); // custo 0,05 > limite 0,04

    wcPostpaid($u->tenant, 1.0);
    expect(wnNotify($u->fresh(), dedupe: 'p2'))->toBe('sent_queued');
});

it('falha da Meta marca o aviso como falho, com o motivo, e não tenta de novo', function () {
    app(AssistantSettings::class)->saveMeta(['phone_number_id' => 'PNID2']);
    Http::fake(['graph.facebook.com/v21.0/PNID2/messages' => Http::response(['error' => ['message' => 'Template does not exist', 'code' => 132001]], 400)]);
    $u = wnUser();
    wnApprove();

    wnNotify($u);

    $n = WhatsAppNotification::query()->first();
    expect($n->status)->toBe('failed')->and($n->reason)->toContain('Template does not exist');
    Http::assertSentCount(1);
});

it('a recusa de um signatário avisa quem criou o documento', function () {
    $u = wnUser();
    wnApprove();
    $e = wzDraft($u, 'Contrato Beta');
    $e->forceFill(['allow_refuse' => true])->save();
    $e->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
    $s = $e->signers()->create(['name' => 'Carlos', 'email' => 'c@x.test', 'role' => 'signer', 'position' => 1, 'status' => 'pending', 'token_hash' => 'h1']);

    app(\App\Envelopes\SigningService::class)->refuse($s->fresh('envelope'), 'Não concordo');

    $n = WhatsAppNotification::query()->where('template_key', 'signer_refused')->first();
    expect($n)->not->toBeNull()->and($n->dedupe_key)->toBe('signer_refused:'.$s->id);
    Http::assertSent(fn ($r) => ($r['template']['name'] ?? null) === 'assinaja_recusou' && $r['template']['components'][0]['parameters'][0]['text'] === 'Carlos');
});

it('o aviso de contrato a vencer sai uma vez por vencimento, mesmo sem e-mail cadastrado', function () {
    $u = wnUser();
    wnApprove();
    $e = wzDraft($u, 'Contrato Gama');
    $e->forceFill(['status' => 'completed', 'completed_at' => now(), 'contract_expires_on' => '2026-12-20', 'contract_alert_days' => 30, 'contract_alert_emails' => []])->save();

    app(\App\Contracts\ContractAlerts::class)->send();
    app(\App\Contracts\ContractAlerts::class)->send();

    expect(WhatsAppNotification::query()->where('template_key', 'contract_expiring')->count())->toBe(1);
    Http::assertSent(fn ($r) => ($r['template']['name'] ?? null) === 'assinaja_contrato_vence' && $r['template']['components'][0]['parameters'][1]['text'] === '20/12/2026');
});

it('documento parado há mais dias que o limite gera um aviso, e só um por parada', function () {
    Setting::put('assistant.stalled_days', '3');
    $u = wnUser();
    wnApprove();
    $old = wzDraft($u, 'Contrato Parado');
    $old->forceFill(['status' => 'sent', 'sent_at' => now()->subDays(5)])->save();
    $recent = wzDraft($u, 'Contrato Recente');
    $recent->forceFill(['status' => 'sent', 'sent_at' => now()->subDay()])->save();

    expect(app(StalledDocuments::class)->run())->toBe(1)->and(app(StalledDocuments::class)->run())->toBe(0);

    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r['template']['components'][0]['parameters'][0]['text'] === 'Contrato Parado' && $r['template']['components'][0]['parameters'][1]['text'] === '5');
});

it('uma assinatura que movimenta o documento reinicia a contagem de parado', function () {
    Setting::put('assistant.stalled_days', '3');
    $u = wnUser();
    wnApprove();
    $e = wzDraft($u, 'Contrato Andando');
    $e->forceFill(['status' => 'in_progress', 'sent_at' => now()->subDays(10)])->save();
    $e->signers()->create(['name' => 'Ana', 'email' => 'a@x.test', 'role' => 'signer', 'position' => 1, 'status' => 'signed', 'signed_at' => now()->subDay(), 'token_hash' => 'h2']);

    expect(app(StalledDocuments::class)->run())->toBe(0);
});

it('o comando e o agendamento do aviso de parado existem', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');

    expect($events)->toContain('documents:notify-stalled')->and(array_key_exists('documents:notify-stalled', \Illuminate\Support\Facades\Artisan::all()))->toBeTrue();
});
