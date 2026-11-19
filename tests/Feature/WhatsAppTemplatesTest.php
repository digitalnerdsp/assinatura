<?php

declare(strict_types=1);

use App\Assistant\AssistantSettings;
use App\Assistant\Channel\MetaException;
use App\Assistant\Channel\MetaWhatsAppClient;
use App\Assistant\Notifications\NotificationCatalog;
use App\Assistant\Notifications\TemplateStore;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(AssistantSettings::class)->saveMeta(['access_token' => 'EAAB', 'phone_number_id' => 'PNID1', 'waba_id' => 'WABA1', 'graph_version' => 'v21.0']);
});

function wtSuper(): User
{
    $t = \App\Models\Tenant::create(['name' => 'Plataforma '.uniqid()]);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'Super', 'email' => 'super'.uniqid().'@wt.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['platform_role' => 'super_admin'])->save();

    return $u;
}

it('o catálogo tem as cinco situações com texto padrão válido', function () {
    $keys = array_keys(NotificationCatalog::all());

    expect($keys)->toBe(['document_completed', 'signer_signed', 'signer_refused', 'contract_expiring', 'document_stalled']);
    foreach (NotificationCatalog::all() as $key => $def) {
        expect(NotificationCatalog::validateBody($key, $def['body']))->toBeNull();
    }
});

it('valida as variáveis do texto: todas, em sequência, fora do começo e do fim', function () {
    expect(NotificationCatalog::validateBody('signer_signed', '{{1}} assinou {{2}} agora'))->toContain('começo')
        ->and(NotificationCatalog::validateBody('signer_signed', 'Olá {{1}} assinou o {{2}}'))->toContain('fim')
        ->and(NotificationCatalog::validateBody('signer_signed', 'Olá, {{1}} assinou o documento.'))->toContain('{{2}}')
        ->and(NotificationCatalog::validateBody('signer_signed', 'Olá, {{1}} assinou o {{3}} ok.'))->not->toBeNull()
        ->and(NotificationCatalog::validateBody('signer_signed', ''))->not->toBeNull()
        ->and(NotificationCatalog::validateBody('signer_signed', str_repeat('a', 1100).' {{1}} x {{2}} y'))->toContain('1024')
        ->and(NotificationCatalog::validateBody('signer_signed', 'Olá, {{1}} assinou o {{2}} ok.'))->toBeNull();
});

it('o repositório cria um rascunho desligado para cada situação, uma vez só', function () {
    $store = app(TemplateStore::class);

    $all = $store->all();
    $store->all();

    expect($all)->toHaveCount(5)->and(WhatsAppTemplate::query()->count())->toBe(5);
    $t = $store->find('document_completed');
    expect($t->status)->toBe('rascunho')->and($t->enabled)->toBeFalse()->and($t->language)->toBe('pt_BR')->and($t->category)->toBe('utility')
        ->and($t->meta_name)->toBe('assinaja_documento_assinado');
});

it('só usa o modelo aprovado e ligado', function () {
    $store = app(TemplateStore::class);
    $t = $store->find('signer_signed');
    expect($store->usable('signer_signed'))->toBeNull();

    $t->forceFill(['status' => 'aprovado', 'enabled' => true])->save();
    expect($store->usable('signer_signed')?->meta_name)->toBe('assinaja_assinou');

    $t->forceFill(['enabled' => false])->save();
    expect($store->usable('signer_signed'))->toBeNull();
});

it('envia o modelo à Meta com nome, idioma e variáveis', function () {
    Http::fake(['graph.facebook.com/v21.0/PNID1/messages' => Http::response(['messages' => [['id' => 'wamid.TPL']]])]);

    $id = app(MetaWhatsAppClient::class)->sendTemplate('5511999990000', 'assinaja_assinou', 'pt_BR', ['Maria', 'Contrato X']);

    expect($id)->toBe('wamid.TPL');
    Http::assertSent(function ($r) {
        $b = $r->data();

        return $b['type'] === 'template' && $b['to'] === '5511999990000' && $b['template']['name'] === 'assinaja_assinou'
            && $b['template']['language']['code'] === 'pt_BR'
            && $b['template']['components'][0]['parameters'] === [['type' => 'text', 'text' => 'Maria'], ['type' => 'text', 'text' => 'Contrato X']];
    });
});

it('cria o modelo na Meta (precisa do WABA) e guarda o identificador', function () {
    Http::fake(['graph.facebook.com/v21.0/WABA1/message_templates' => Http::response(['id' => 'TPLID9', 'status' => 'PENDING', 'category' => 'UTILITY'])]);
    $t = app(TemplateStore::class)->find('signer_signed');

    app(TemplateStore::class)->submit($t);

    $t->refresh();
    expect($t->status)->toBe('enviado')->and($t->meta_template_id)->toBe('TPLID9');
    Http::assertSent(function ($r) use ($t) {
        $b = $r->data();

        return $b['name'] === $t->meta_name && $b['language'] === 'pt_BR' && $b['category'] === 'UTILITY'
            && $b['components'][0]['type'] === 'BODY' && $b['components'][0]['text'] === $t->body
            && count($b['components'][0]['example']['body_text'][0]) === 2;
    });
});

it('sem o WABA configurado, o envio para aprovação explica o que falta', function () {
    \App\Models\Setting::query()->where('key', 'assistant.meta')->delete();
    app(AssistantSettings::class)->saveMeta(['access_token' => 'EAAB', 'phone_number_id' => 'PNID1']);
    Http::fake();
    $t = app(TemplateStore::class)->find('signer_signed');

    expect(fn () => app(TemplateStore::class)->submit($t))->toThrow(MetaException::class, 'WABA');
});

it('atualiza o status com o que a Meta diz (aprovado, rejeitado, pausado)', function () {
    $store = app(TemplateStore::class);
    foreach (['signer_signed', 'signer_refused', 'contract_expiring'] as $k) {
        $store->find($k)->forceFill(['status' => 'enviado'])->save();
    }
    Http::fake(['graph.facebook.com/v21.0/WABA1/message_templates*' => Http::response(['data' => [
        ['name' => 'assinaja_assinou', 'status' => 'APPROVED', 'category' => 'MARKETING'],
        ['name' => 'assinaja_recusou', 'status' => 'REJECTED', 'rejected_reason' => 'INVALID_FORMAT'],
        ['name' => 'assinaja_contrato_vence', 'status' => 'PAUSED'],
    ]])]);

    $n = $store->sync();

    expect($n)->toBe(3)
        ->and($store->find('signer_signed')->status)->toBe('aprovado')->and($store->find('signer_signed')->category)->toBe('marketing')
        ->and($store->find('signer_refused')->status)->toBe('rejeitado')->and($store->find('signer_refused')->rejected_reason)->toBe('INVALID_FORMAT')
        ->and($store->find('contract_expiring')->status)->toBe('pausado');
});

it('a tela é só do super administrador e salva o texto validado', function () {
    $super = wtSuper();
    $plain = User::create(['tenant_id' => \App\Models\Tenant::create(['name' => 'X'])->id, 'name' => 'Dono', 'email' => 'dono@wt.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);

    $this->actingAs($plain)->get(route('admin.notifications'))->assertNotFound();
    $this->actingAs($super)->get(route('admin.notifications'))->assertOk()->assertSee('Documento assinado por todos');

    $this->actingAs($super)->put(route('admin.notifications.update', 'signer_signed'), ['meta_name' => 'meu_modelo', 'body' => 'Olá, {{1}} assinou o {{2}} ok.', 'language' => 'pt_BR', 'enabled' => '1'])->assertRedirect();
    $t = app(TemplateStore::class)->find('signer_signed');
    expect($t->meta_name)->toBe('meu_modelo')->and($t->body)->toBe('Olá, {{1}} assinou o {{2}} ok.');

    $this->actingAs($super)->put(route('admin.notifications.update', 'signer_signed'), ['meta_name' => 'meu_modelo', 'body' => '{{1}} no começo {{2}} fim', 'language' => 'pt_BR'])->assertSessionHasErrors('body');
    $this->actingAs($super)->put(route('admin.notifications.update', 'signer_signed'), ['meta_name' => 'Nome Inválido!', 'body' => 'Olá, {{1}} assinou o {{2}} ok.', 'language' => 'pt_BR'])->assertSessionHasErrors('meta_name');
    $this->actingAs($super)->put(route('admin.notifications.update', 'inexistente'), [])->assertNotFound();
});

it('mudar o texto de um modelo já aprovado o devolve para rascunho (a Meta precisa aprovar de novo)', function () {
    $super = wtSuper();
    $t = app(TemplateStore::class)->find('signer_signed');
    $t->forceFill(['status' => 'aprovado', 'enabled' => true])->save();

    $this->actingAs($super)->put(route('admin.notifications.update', 'signer_signed'), ['meta_name' => $t->meta_name, 'body' => 'Atenção, {{1}} assinou o {{2}} agora.', 'language' => 'pt_BR', 'enabled' => '1'])->assertRedirect();

    expect($t->refresh()->status)->toBe('rascunho')->and($t->enabled)->toBeFalse();
});

it('o menu da plataforma tem o link dos avisos do WhatsApp', function () {
    $labels = collect(app(\App\Themes\MenuBuilder::class)->for(wtSuper()))->pluck('label')->all();

    expect($labels)->toContain('Avisos do WhatsApp');
});
