<?php

declare(strict_types=1);

use App\Assistant\AssistantOrchestrator;
use App\Assistant\AssistantSettings;
use App\Assistant\Channel\InboundMessage;
use App\Assistant\Flow\FlowStore;
use App\Assistant\Flow\SendDocumentFlow;
use App\Enums\EnvelopeStatus;
use App\Mail\SignatureRequestedMail;
use App\Models\Contact;
use App\Models\Envelope;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSigningSetting;
use App\Models\User;
use App\Plans\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const WS_PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-12-10 12:00:00');
    Storage::fake('local');
    Mail::fake();
    app(AssistantSettings::class)->saveMeta(['access_token' => 'EAAB', 'phone_number_id' => 'PNID1', 'graph_version' => 'v21.0']);
    Http::fake([
        'graph.facebook.com/v21.0/MEDIA1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/1', 'mime_type' => 'application/pdf']),
        'graph.facebook.com/v21.0/MEDIABAD' => Http::response(['url' => 'https://evil.example.com/x', 'mime_type' => 'application/pdf']),
        'graph.facebook.com/v21.0/MEDIAEXE' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/2', 'mime_type' => 'application/pdf']),
        'lookaside.fbsbx.com/whatsapp_business/attachments/1' => Http::response(WS_PDF),
        'lookaside.fbsbx.com/whatsapp_business/attachments/2' => Http::response('MZ\x90 binario'),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]]),
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function wsUser(array $planLimits = [], array $features = ['assistant' => true]): User
{
    static $n = 0;
    $n++;
    $plan = Plan::create(['name' => 'WS'.$n, 'slug' => 'ws-'.$n, 'active' => true, 'features' => $features, 'limits' => $planLimits]);
    $tenant = Tenant::create(['name' => 'Empresa WS '.$n]);
    app(PlanService::class)->assign($tenant, $plan);
    $u = User::create(['tenant_id' => $tenant->id, 'name' => 'Ana Souza', 'email' => "ana{$n}@ws.test", 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $u->forceFill(['whatsapp_phone' => '551196'.str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'whatsapp_verified_at' => now()])->save();

    return $u->fresh();
}

function wsText(User $u, string $text): ?\App\Assistant\AssistantReply
{
    static $i = 0;
    $i++;

    return app(SendDocumentFlow::class)->handle($u, new InboundMessage('wamid.T'.$i, $u->whatsapp_phone, 'text', $text, timestamp: time()));
}

function wsBtn(User $u, string $id, string $title = 'x'): ?\App\Assistant\AssistantReply
{
    static $i = 0;
    $i++;

    return app(SendDocumentFlow::class)->handle($u, new InboundMessage('wamid.B'.$i, $u->whatsapp_phone, 'button', $title, payloadId: $id, timestamp: time()));
}

function wsDoc(User $u, string $media = 'MEDIA1', string $name = 'Contrato de Aluguel.pdf', string $mime = 'application/pdf'): ?\App\Assistant\AssistantReply
{
    static $i = 0;
    $i++;

    return app(SendDocumentFlow::class)->handle($u, new InboundMessage('wamid.D'.$i, $u->whatsapp_phone, 'document', null, mediaId: $media, mime: $mime, filename: $name, timestamp: time()));
}

/** Leva o fluxo até a revisão com um signatário. */
function wsToReview(User $u, array $signers = ['Maria Souza maria@email.com']): void
{
    wsDoc($u);
    wsBtn($u, 'flow_title_keep');
    foreach ($signers as $s) {
        wsText($u, $s);
    }
    wsBtn($u, 'flow_done');
}

function wsButtonIds(?\App\Assistant\AssistantReply $r): array
{
    return array_map(fn ($b) => explode(':', $b['id'])[0], $r->buttons ?? []); // sem o carimbo do envio
}

// ------------------------------------------------------------------ arquivo → rascunho → título

it('o arquivo vira um rascunho do site e o assistente pergunta o título', function () {
    $u = wsUser();

    $r = wsDoc($u);

    $e = Envelope::query()->where('tenant_id', $u->tenant_id)->first();
    expect($e->status)->toBe(EnvelopeStatus::Draft)->and($e->created_by)->toBe($u->id)->and($e->title)->toBe('Contrato de Aluguel')
        ->and($r->text)->toContain('Contrato de Aluguel')->and(wsButtonIds($r))->toBe(['flow_title_keep', 'flow_cancel'])
        ->and(app(FlowStore::class)->current($u)->step)->toBe('title');
    expect(Storage::disk('local')->allFiles())->not->toBeEmpty();
});

it('o título pode ser mantido pelo botão ou digitado', function () {
    $u = wsUser();
    wsDoc($u);
    $r = wsText($u, '  Contrato   de locação — Sala 3  ');

    expect(Envelope::query()->first()->title)->toBe('Contrato de locação — Sala 3')
        ->and(app(FlowStore::class)->current($u)->step)->toBe('signers')->and($r->text)->toContain('Quem vai assinar');

    $v = wsUser();
    wsDoc($v);
    wsBtn($v, 'flow_title_keep');
    expect(app(FlowStore::class)->current($v)->step)->toBe('signers')->and(Envelope::query()->where('tenant_id', $v->tenant_id)->value('title'))->toBe('Contrato de Aluguel');
});

it('título gigante é cortado e controle de texto removido', function () {
    $u = wsUser();
    wsDoc($u);
    wsText($u, "Título\x00 com lixo ".str_repeat('x', 400));

    $t = Envelope::query()->first()->title;
    expect(mb_strlen($t))->toBeLessThanOrEqual(190)->and($t)->not->toContain("\x00");
});

// ------------------------------------------------------------------ signatários

it('cada mensagem acrescenta um signatário e confirma', function () {
    $u = wsUser();
    wsDoc($u);
    wsBtn($u, 'flow_title_keep');

    $r1 = wsText($u, 'Maria Souza maria@email.com');
    $r2 = wsText($u, 'joao@empresa.com');

    expect($r1->text)->toContain('Maria Souza')->and(wsButtonIds($r1))->toBe(['flow_done', 'flow_cancel'])
        ->and(app(FlowStore::class)->current($u)->data['signers'])->toBe([['name' => 'Maria Souza', 'email' => 'maria@email.com'], ['name' => 'Joao', 'email' => 'joao@empresa.com']])
        ->and($r2->text)->toContain('2');
});

it('sem e-mail: usa o contato salvo quando há um só, senão pede o e-mail', function () {
    $u = wsUser();
    Contact::query()->create(['tenant_id' => $u->tenant_id, 'name' => 'Carlos Lima', 'email' => 'carlos@lima.com']);
    Contact::query()->create(['tenant_id' => $u->tenant_id, 'name' => 'Paula Reis', 'email' => 'paula1@x.com']);
    Contact::query()->create(['tenant_id' => $u->tenant_id, 'name' => 'Paula Reis Neto', 'email' => 'paula2@x.com']);
    wsDoc($u);
    wsBtn($u, 'flow_title_keep');

    $ok = wsText($u, 'Carlos Lima');
    $many = wsText($u, 'Paula Reis');
    $none = wsText($u, 'Fulano de Tal');

    expect($ok->text)->toContain('carlos@lima.com')
        ->and($many->text)->toContain('e-mail')->and($none->text)->toContain('e-mail')
        ->and(count(app(FlowStore::class)->current($u)->data['signers']))->toBe(1);
});

it('contato de outra empresa nunca é usado', function () {
    $u = wsUser();
    $other = wsUser();
    Contact::query()->create(['tenant_id' => $other->tenant_id, 'name' => 'Segredo Alheio', 'email' => 'segredo@outra.com']);
    wsDoc($u);
    wsBtn($u, 'flow_title_keep');

    $r = wsText($u, 'Segredo Alheio');

    expect($r->text)->not->toContain('segredo@outra.com')->and(app(FlowStore::class)->current($u)->data['signers'] ?? [])->toBe([]);
});

it('e-mail repetido, mais de um e-mail e o 21º signatário são recusados com explicação', function () {
    $u = wsUser();
    wsDoc($u);
    wsBtn($u, 'flow_title_keep');
    wsText($u, 'Maria maria@email.com');

    expect(wsText($u, 'Maria de novo MARIA@email.com')->text)->toContain('já está')
        ->and(wsText($u, 'a@x.com b@y.com')->text)->toContain('um por mensagem');

    $f = app(FlowStore::class)->current($u);
    $people = array_map(fn ($i) => ['name' => "P{$i}", 'email' => "p{$i}@x.com"], range(1, 20));
    app(FlowStore::class)->advance($f, 'signers', ['signers' => $people]);
    expect(wsText($u, 'Extra extra@x.com')->text)->toContain('20');
});

it('Pronto sem ninguém na lista não avança', function () {
    $u = wsUser();
    wsDoc($u);
    wsBtn($u, 'flow_title_keep');

    $r = wsBtn($u, 'flow_done');

    expect($r->text)->toContain('ao menos')->and(app(FlowStore::class)->current($u)->step)->toBe('signers');
});

// ------------------------------------------------------------------ revisão

it('a revisão mostra título, pessoas, ordem, prazo e como os links saem', function () {
    $u = wsUser();
    wsToReview($u, ['Maria Souza maria@email.com', 'joao@empresa.com']);

    $r = wsText($u, 'tchau'); // texto solto na revisão não envia nada e mostra de novo
    $rev = wsBtn($u, 'flow_order');

    expect($rev->text)->toContain('Contrato de Aluguel')->toContain('1. Maria Souza')->toContain('2. Joao')->toContain('um de cada vez')->toContain('e-mail')
        ->and(wsButtonIds($rev))->toBe(['flow_send', 'flow_order', 'flow_cancel'])
        ->and(Envelope::query()->first()->status)->toBe(EnvelopeStatus::Draft);

    expect(wsBtn($u, 'flow_order')->text)->toContain('ao mesmo tempo');
});

it('na revisão, uma pessoa digitada é acrescentada', function () {
    $u = wsUser();
    wsToReview($u);

    $r = wsText($u, 'Pedro pedro@x.com');

    expect($r->text)->toContain('2. Pedro')->and(count(app(FlowStore::class)->current($u)->data['signers']))->toBe(2);
});

// ------------------------------------------------------------------ envio

it('Enviar agora cria os signatários, envia, manda os links por e-mail e encerra o fluxo', function () {
    $u = wsUser();
    wsToReview($u, ['Maria Souza maria@email.com', 'joao@empresa.com']);

    $r = wsBtn($u, 'flow_send');

    $e = Envelope::query()->first();
    expect($e->status)->toBe(EnvelopeStatus::Sent)->and($e->sent_at)->not->toBeNull()
        ->and($e->signers()->orderBy('position')->pluck('email')->all())->toBe(['maria@email.com', 'joao@empresa.com'])
        ->and($e->signers()->whereNotNull('invited_at')->count())->toBe(2)
        ->and($r->text)->toContain('enviado')->toContain('2')->and(app(FlowStore::class)->current($u))->toBeNull();
    Mail::assertSent(SignatureRequestedMail::class, 2);
});

it('um um de cada vez só convida o primeiro', function () {
    $u = wsUser();
    wsToReview($u, ['Maria maria@email.com', 'joao@empresa.com']);
    wsBtn($u, 'flow_order');

    wsBtn($u, 'flow_send');

    expect(Envelope::query()->first()->signing_order->value)->toBe('sequential');
    Mail::assertSent(SignatureRequestedMail::class, 1);
});

it('tocar duas vezes em Enviar agora envia uma vez só', function () {
    $u = wsUser();
    wsToReview($u);

    wsBtn($u, 'flow_send');
    $again = wsBtn($u, 'flow_send');

    Mail::assertSent(SignatureRequestedMail::class, 1);
    expect(Envelope::query()->count())->toBe(1)->and($again->text)->toContain('em andamento'); // fluxo encerrado: o segundo toque só explica
});

it('enviar com o fluxo já em "enviando" não envia de novo', function () {
    $u = wsUser();
    wsToReview($u);
    app(FlowStore::class)->advance(app(FlowStore::class)->current($u), 'sending');

    $r = wsBtn($u, 'flow_send');

    expect($r->text)->toContain('já foi iniciado');
    Mail::assertNothingSent();
    expect(Envelope::query()->first()->status)->toBe(EnvelopeStatus::Draft);
});

it('falha no envio mantém o rascunho e volta para a revisão com a explicação', function () {
    $u = wsUser();
    wsToReview($u);
    Envelope::query()->first()->tenant->forceFill(['suspended_at' => now()])->save();

    $r = wsBtn($u, 'flow_send');

    expect($r->text)->toContain('suspensa')->and(Envelope::query()->first()->status)->toBe(EnvelopeStatus::Draft)->and(app(FlowStore::class)->current($u)->step)->toBe('review');
    Mail::assertNothingSent();
});

it('o documento enviado aparece no site e o rascunho não é duplicado', function () {
    $u = wsUser();
    wsToReview($u);
    wsBtn($u, 'flow_send');

    $this->actingAs($u)->get(route('envelopes.index'))->assertOk()->assertSee('Contrato de Aluguel');
    expect(Envelope::query()->where('tenant_id', $u->tenant_id)->count())->toBe(1);
});

// ------------------------------------------------------------------ cancelar, expirar, novo arquivo

it('Cancelar manda o rascunho para a lixeira e encerra o fluxo', function () {
    $u = wsUser();
    wsDoc($u);

    $r = wsBtn($u, 'flow_cancel');

    expect($r->text)->toContain('Cancelado')->and(Envelope::query()->first()->trashed_at)->not->toBeNull()->and(app(FlowStore::class)->current($u))->toBeNull();
});

it('a palavra "cancelar" também cancela', function () {
    $u = wsUser();
    wsDoc($u);
    wsBtn($u, 'flow_title_keep');

    wsText($u, 'cancelar');

    expect(app(FlowStore::class)->current($u))->toBeNull();
});

it('depois de 30 minutos o fluxo some e o texto volta para o assistente normal', function () {
    $u = wsUser();
    wsDoc($u);
    CarbonImmutable::setTestNow('2026-12-10 12:45:00');

    expect(wsText($u, 'Maria maria@email.com'))->toBeNull();
    expect(Envelope::query()->first()->status)->toBe(EnvelopeStatus::Draft); // o rascunho continua no site
});

it('arquivo novo no meio do fluxo pergunta se descarta o anterior', function () {
    $u = wsUser();
    wsDoc($u, name: 'Primeiro.pdf');

    $ask = wsDoc($u, name: 'Segundo.pdf');
    expect(wsButtonIds($ask))->toBe(['flow_discard', 'flow_keep'])->and(Envelope::query()->count())->toBe(1);

    $keep = wsBtn($u, 'flow_keep');
    expect($keep->text)->toContain('Primeiro')->and(Envelope::query()->count())->toBe(1);

    wsDoc($u, name: 'Segundo.pdf');
    $new = wsBtn($u, 'flow_discard');

    expect(Envelope::query()->whereNull('trashed_at')->count())->toBe(1)->and(Envelope::query()->whereNull('trashed_at')->value('title'))->toBe('Segundo')
        ->and($new->text)->toContain('Segundo');
});

it('o texto "enviar documento" pede o arquivo', function () {
    $u = wsUser();

    $r = wsText($u, 'Quero enviar documento para assinar');

    expect($r->text)->toContain('PDF')->toContain('Word')->and(Envelope::query()->count())->toBe(0);
});

it('texto qualquer sem fluxo não é do fluxo (vai para o assistente)', function () {
    expect(wsText(wsUser(), 'quais documentos estão pendentes?'))->toBeNull();
});

// ------------------------------------------------------------------ arquivos e regras

it('arquivo que não é PDF nem Word é recusado sem criar rascunho', function () {
    $u = wsUser();

    expect(wsDoc($u, 'MEDIAEXE', 'virus.pdf')->text)->toContain('PDF')
        ->and(wsDoc($u, 'MEDIA1', 'planilha.xlsx', 'application/vnd.ms-excel')->text)->toContain('PDF')
        ->and(Envelope::query()->count())->toBe(0)->and(app(FlowStore::class)->current($u))->toBeNull();
});

it('endereço de mídia fora da Meta é recusado', function () {
    $u = wsUser();

    expect(wsDoc($u, 'MEDIABAD')->text)->toContain('baixar')->and(Envelope::query()->count())->toBe(0);
});

it('PDF maior que o limite do sistema é recusado', function () {
    config(['signing.max_pdf_mb' => 0]);
    $u = wsUser();

    expect(wsDoc($u)->text)->toContain('grande')->and(Envelope::query()->count())->toBe(0);
});

it('imagem, áudio e outros tipos não iniciam o fluxo', function () {
    $u = wsUser();

    $r = app(SendDocumentFlow::class)->handle($u, new InboundMessage('wamid.I1', $u->whatsapp_phone, 'other', null, timestamp: time()));

    expect($r)->toBeNull();
});

it('política "sempre assina" da empresa explica e não cria nada', function () {
    $u = wsUser();
    TenantSigningSetting::query()->create(['tenant_id' => $u->tenant_id, 'sender_policy' => 'always']);

    $r = wsDoc($u);

    expect($r->text)->toContain('site')->and(Envelope::query()->count())->toBe(0)->and(app(FlowStore::class)->current($u))->toBeNull();
});

it('plano sem documentos disponíveis avisa antes de pedir signatários', function () {
    $u = wsUser(['documents' => 0]);

    $r = wsDoc($u);

    expect($r->text)->not->toBe('')->and(Envelope::query()->count())->toBe(0);
});

it('empresa que desligou o grupo de envio não recebe o fluxo', function () {
    $u = wsUser();
    $u->tenant->forceFill(['assistant_tools' => ['consult']])->save();

    $r = wsDoc($u->fresh());

    expect($r->text)->toContain('não está liberado')->and(Envelope::query()->count())->toBe(0);
});

// ------------------------------------------------------------------ integração com o orquestrador

it('o orquestrador entrega o arquivo ao fluxo mesmo sem saldo de tokens', function () {
    $u = wsUser();
    $msg = new InboundMessage('wamid.D900', $u->whatsapp_phone, 'document', null, mediaId: 'MEDIA1', mime: 'application/pdf', filename: 'x.pdf', timestamp: time());

    $r = app(AssistantOrchestrator::class)->handle($u, $msg);

    expect($r->text)->toContain('título')->and(Envelope::query()->count())->toBe(1);
});

it('as travas do orquestrador valem para o fluxo (usuário com assistente desligado)', function () {
    $u = wsUser();
    $u->forceFill(['assistant_enabled' => false])->save();
    $msg = new InboundMessage('wamid.D901', $u->whatsapp_phone, 'document', null, mediaId: 'MEDIA1', mime: 'application/pdf', filename: 'x.pdf', timestamp: time());

    $r = app(AssistantOrchestrator::class)->handle($u->fresh(), $msg);

    expect($r->text)->toContain('desativado')->and(Envelope::query()->count())->toBe(0);
});

it('os botões do menu fixo continuam funcionando durante um fluxo', function () {
    $u = wsUser();
    wsDoc($u);

    $r = wsBtn($u, 'menu_pendentes');

    expect($r)->toBeNull(); // não é botão do fluxo: segue para o menu
    expect(app(FlowStore::class)->current($u))->not->toBeNull();
});
