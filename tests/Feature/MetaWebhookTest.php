<?php

declare(strict_types=1);

use App\Assistant\AssistantSettings;
use App\Assistant\Channel\InboundMessage;
use App\Jobs\ProcessInboundWhatsApp;
use App\Models\MetaMessageUsage;
use App\Models\Setting;
use App\Models\WhatsAppInbound;
use App\Models\WhatsAppStatusEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const META_SECRET = 'segredo-do-app';
const META_VERIFY = 'token-de-verificacao';

beforeEach(function () {
    Queue::fake();
    app(AssistantSettings::class)->saveMeta([
        'access_token' => 'EAAB-token', 'phone_number_id' => 'PNID123', 'app_secret' => META_SECRET, 'verify_token' => META_VERIFY,
    ]);
    Setting::put('assistant.markup_percent', '10');
    Setting::put('assistant.meta_rates', json_encode(['utility' => 0.04, 'marketing' => 0.35, 'service' => 0]));
});

function metaFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/meta/{$name}.json"));
}

function metaPost(string $body, ?string $signature = 'auto')
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($signature === 'auto') {
        $signature = 'sha256='.hash_hmac('sha256', $body, META_SECRET);
    }
    if ($signature !== null) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
    }

    return test()->call('POST', '/webhooks/meta/whatsapp', [], [], [], $server, $body);
}

// ------------------------------------------------------------------ verificação (GET)

it('o GET devolve o desafio quando o token de verificação confere', function () {
    $r = $this->get('/webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token='.META_VERIFY.'&hub.challenge=12345');

    $r->assertOk();
    expect($r->getContent())->toBe('12345');
});

it('o GET recusa token errado, ausente ou modo diferente', function () {
    $this->get('/webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token=errado&hub.challenge=1')->assertForbidden();
    $this->get('/webhooks/meta/whatsapp?hub.mode=subscribe&hub.challenge=1')->assertForbidden();
    $this->get('/webhooks/meta/whatsapp?hub.mode=outro&hub.verify_token='.META_VERIFY.'&hub.challenge=1')->assertForbidden();
});

it('sem token configurado, o GET recusa até um token vazio', function () {
    Setting::query()->where('key', 'assistant.meta')->delete();

    $this->get('/webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=1')->assertForbidden();
});

// ------------------------------------------------------------------ assinatura (POST)

it('assinatura ausente, inválida ou de outro corpo: 401 e nada é gravado', function () {
    $body = metaFixture('text');

    metaPost($body, null)->assertStatus(401);
    metaPost($body, 'sha256='.str_repeat('0', 64))->assertStatus(401);
    metaPost($body, 'sha256='.hash_hmac('sha256', $body.' ', META_SECRET))->assertStatus(401);
    metaPost($body, hash_hmac('sha256', $body, META_SECRET))->assertStatus(401); // sem o prefixo sha256=
    expect(WhatsAppInbound::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('sem o segredo do app configurado, tudo é recusado (falha fechada)', function () {
    Setting::query()->where('key', 'assistant.meta')->delete();
    $body = metaFixture('text');

    metaPost($body, 'sha256='.hash_hmac('sha256', $body, ''))->assertStatus(401);
    expect(WhatsAppInbound::query()->count())->toBe(0);
});

it('assinatura válida: 200, grava a mensagem e despacha o processamento', function () {
    metaPost(metaFixture('text'))->assertOk();

    $row = WhatsAppInbound::query()->first();
    expect(WhatsAppInbound::query()->count())->toBe(1)->and($row->wa_message_id)->toBe('wamid.T1')->and($row->from_phone)->toBe('5511999990000')->and($row->type)->toBe('text');
    Queue::assertPushed(ProcessInboundWhatsApp::class, fn ($j) => $j->inboundId === $row->id);
});

it('a mesma mensagem duas vezes gera um registro e um job só', function () {
    metaPost(metaFixture('text'))->assertOk();
    metaPost(metaFixture('text'))->assertOk();

    expect(WhatsAppInbound::query()->count())->toBe(1);
    Queue::assertPushed(ProcessInboundWhatsApp::class, 1);
});

it('vários eventos num corpo viram vários registros', function () {
    metaPost(metaFixture('multiple'))->assertOk();

    expect(WhatsAppInbound::query()->orderBy('wa_message_id')->pluck('wa_message_id')->all())->toBe(['wamid.M1', 'wamid.M2']);
    Queue::assertPushed(ProcessInboundWhatsApp::class, 2);
});

it('mensagem de outro número da Meta (outro phone_number_id) é ignorada', function () {
    $body = str_replace('PNID123', 'OUTRO999', metaFixture('text'));

    metaPost($body)->assertOk();

    expect(WhatsAppInbound::query()->count())->toBe(0);
});

it('corpo que não é JSON com assinatura válida: 400, nada gravado', function () {
    metaPost('isto não é json')->assertStatus(400);
    expect(WhatsAppInbound::query()->count())->toBe(0);
});

it('o corpo inteiro é validado como veio: um espaço a mais invalida a assinatura', function () {
    $body = metaFixture('text');
    $sig = 'sha256='.hash_hmac('sha256', $body, META_SECRET);

    metaPost($body.' ', $sig)->assertStatus(401);
});

// ------------------------------------------------------------------ tipos de mensagem

it('entende texto, botão, lista, documento e trata o resto como "other"', function () {
    $parse = fn (string $fixture) => InboundMessage::fromMeta(json_decode(metaFixture($fixture), true)['entry'][0]['changes'][0]['value']['messages'][0]);

    $t = $parse('text');
    expect($t->type)->toBe('text')->and($t->text)->toBe('Quais documentos estão pendentes?')->and($t->from)->toBe('5511999990000')->and($t->waMessageId)->toBe('wamid.T1')->and($t->timestamp)->toBe(1796000000);

    $b = $parse('button');
    expect($b->type)->toBe('button')->and($b->payloadId)->toBe('menu_pendentes')->and($b->text)->toBe('Pendentes');

    $l = $parse('list');
    expect($l->type)->toBe('list')->and($l->payloadId)->toBe('doc_42')->and($l->text)->toBe('Contrato');

    $d = $parse('document');
    expect($d->type)->toBe('document')->and($d->mediaId)->toBe('MEDIA9')->and($d->mime)->toBe('application/pdf')->and($d->filename)->toBe('contrato.pdf');

    $i = $parse('image');
    expect($i->type)->toBe('other')->and($i->text)->toBeNull();
});

it('o registro guarda só o necessário (sem o conteúdo de arquivos)', function () {
    metaPost(metaFixture('document'))->assertOk();

    $row = WhatsAppInbound::query()->first();
    expect($row->type)->toBe('document')->and($row->payload)->toMatchArray(['mediaId' => 'MEDIA9', 'filename' => 'contrato.pdf']);
});

// ------------------------------------------------------------------ preço das mensagens (status)

it('status com preço cobrável grava o uso da empresa uma vez só, com o acréscimo', function () {
    $u = wzOwner();
    $u->forceFill(['whatsapp_phone' => '5511999990000'])->save();

    metaPost(metaFixture('status_priced'))->assertOk();
    metaPost(metaFixture('status_priced'))->assertOk();

    $row = MetaMessageUsage::query()->first();
    expect(MetaMessageUsage::query()->count())->toBe(1)->and($row->tenant_id)->toBe($u->tenant_id)->and($row->category)->toBe('utility')->and($row->billable)->toBeTrue()
        ->and((float) $row->meta_amount)->toBe(0.04)->and((float) $row->charged_amount)->toBe(0.044);
});

it('status não cobrável fica registrado com valor zero', function () {
    $u = wzOwner();
    $u->forceFill(['whatsapp_phone' => '5511999990000'])->save();

    metaPost(metaFixture('status_free'))->assertOk();

    $row = MetaMessageUsage::query()->first();
    expect($row->billable)->toBeFalse()->and((float) $row->charged_amount)->toBe(0.0);
});

it('status sem preço não cria uso nem pendência', function () {
    $u = wzOwner();
    $u->forceFill(['whatsapp_phone' => '5511999990000'])->save();

    metaPost(metaFixture('status_plain'))->assertOk();

    expect(MetaMessageUsage::query()->count())->toBe(0)->and(WhatsAppStatusEvent::query()->count())->toBe(0);
});

it('status de um número que ninguém vinculou fica guardado para conciliar, sem cobrar ninguém', function () {
    metaPost(metaFixture('status_priced'))->assertOk();

    expect(MetaMessageUsage::query()->count())->toBe(0);
    $e = WhatsAppStatusEvent::query()->first();
    expect($e->wa_message_id)->toBe('wamid.OUT1')->and($e->category)->toBe('utility')->and($e->tenant_id)->toBeNull();
});

it('o status guardado é cobrado quando o número é vinculado depois', function () {
    metaPost(metaFixture('status_priced'))->assertOk();
    $u = wzOwner();
    $u->forceFill(['whatsapp_phone' => '5511999990000'])->save();

    $n = app(\App\Assistant\Channel\StatusReconciler::class)->run();

    expect($n)->toBe(1)->and(MetaMessageUsage::query()->where('tenant_id', $u->tenant_id)->count())->toBe(1)->and(WhatsAppStatusEvent::query()->whereNull('processed_at')->count())->toBe(0);
});

it('a categoria sem preço na tabela da plataforma é guardada para conciliar (não inventa valor)', function () {
    Setting::put('assistant.meta_rates', json_encode(['marketing' => 0.35]));
    $u = wzOwner();
    $u->forceFill(['whatsapp_phone' => '5511999990000'])->save();

    metaPost(metaFixture('status_priced'))->assertOk();

    expect(MetaMessageUsage::query()->count())->toBe(0)->and(WhatsAppStatusEvent::query()->count())->toBe(1);
});

// ------------------------------------------------------------------ proteção

it('as rotas do webhook ficam fora da verificação de CSRF (a Meta não envia o token)', function () {
    $prop = new ReflectionProperty(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, 'neverVerify');
    $except = $prop->getValue();

    expect($except)->toContain('webhooks/meta/*');
});
