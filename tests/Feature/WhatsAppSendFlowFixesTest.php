<?php

declare(strict_types=1);

use App\Assistant\AssistantSettings;
use App\Assistant\Channel\InboundMessage;
use App\Assistant\Flow\FlowStore;
use App\Assistant\Flow\SendDocumentFlow;
use App\Assistant\Flow\SignerLine;
use App\Enums\EnvelopeStatus;
use App\Mail\SignatureRequestedMail;
use App\Models\Envelope;
use App\Signing\Support\SealerClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-12-10 12:00:00');
    Storage::fake('local');
    Mail::fake();
    app(AssistantSettings::class)->saveMeta(['access_token' => 'EAAB', 'phone_number_id' => 'PNID1', 'graph_version' => 'v21.0']);
    Http::fake([
        'graph.facebook.com/v21.0/MEDIA1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/1', 'mime_type' => 'application/pdf']),
        'graph.facebook.com/v21.0/MEDIACONN' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/9', 'mime_type' => 'application/pdf']),
        'graph.facebook.com/v21.0/MEDIADOCX' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/7', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']),
        'lookaside.fbsbx.com/whatsapp_business/attachments/1' => Http::response(WS_PDF),
        'lookaside.fbsbx.com/whatsapp_business/attachments/9' => fn () => throw new ConnectionException('timeout'),
        'lookaside.fbsbx.com/whatsapp_business/attachments/7' => Http::response("PK\x03\x04 docx de mentira"),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]]),
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Envelope::flushEventListeners();
});

function wfx(User $u, string $id, string $title = 'x'): ?\App\Assistant\AssistantReply
{
    return wsBtn($u, $id, $title);
}

/** Id do botão sem o carimbo do envio (`flow_send:ab12cd34` → `flow_send`). */
function wfxBase(?\App\Assistant\AssistantReply $r): array
{
    return array_map(fn ($b) => explode(':', $b['id'])[0], $r->buttons ?? []);
}

// ------------------------------------------------------------------ 1. nada fica preso em "enviando"

it('uma exceção inesperada no envio devolve o fluxo à revisão, com resposta, e o botão volta a funcionar', function () {
    $u = wsUser();
    wsToReview($u);
    Envelope::updating(fn () => throw new RuntimeException('falha de banco simulada'));

    $r = wsBtn($u, 'flow_send');

    Envelope::flushEventListeners();
    expect($r->text)->toContain('Não consegui enviar')->and(app(FlowStore::class)->current($u)->step)->toBe('review')
        ->and(Envelope::query()->first()->status)->toBe(EnvelopeStatus::Draft);
    Mail::assertNothingSent();

    $again = wsBtn($u, 'flow_send'); // o problema passou: agora envia
    expect($again->text)->toContain('enviado');
    Mail::assertSent(SignatureRequestedMail::class, 1);
});

it('cada passo do envio renova o prazo do fluxo (não expira no meio do envio)', function () {
    $u = wsUser();
    wsToReview($u);
    CarbonImmutable::setTestNow('2026-12-10 12:25:00');

    wsBtn($u, 'flow_send');

    expect(Envelope::query()->first()->status)->toBe(EnvelopeStatus::Sent);
});

// ------------------------------------------------------------------ 2. cancelar durante o envio

it('cancelar ou trocar o arquivo enquanto o envio está em andamento é recusado', function () {
    $u = wsUser();
    wsToReview($u);
    app(FlowStore::class)->advance(app(FlowStore::class)->current($u), 'sending');

    $c = wsBtn($u, 'flow_cancel');
    $d = wsDoc($u, name: 'Outro.pdf');

    expect($c->text)->toContain('em andamento')->and($d->text)->toContain('em andamento')
        ->and(Envelope::query()->whereNotNull('trashed_at')->count())->toBe(0);
});

// ------------------------------------------------------------------ 3 e 4. rede e Word

it('falha de rede ao baixar o arquivo responde e não derruba o job', function () {
    $u = wsUser();

    $r = wsDoc($u, 'MEDIACONN');

    expect($r->text)->toContain('baixar')->and(Envelope::query()->count())->toBe(0);
});

it('um Word é convertido para PDF e entra no fluxo; selador fora do ar responde com clareza', function () {
    $http = new HttpFactory();
    $http->fake(['sealer/v1/convert' => $http->response(WS_PDF)]);
    app()->instance(SealerClient::class, new SealerClient($http, 'http://sealer', 't'));
    $u = wsUser();

    $r = wsDoc($u, 'MEDIADOCX', 'Proposta Comercial.docx');

    expect($r->text)->toContain('Proposta Comercial')->and(Envelope::query()->first()->document->name)->toBe('Proposta Comercial.pdf');

    $down = new HttpFactory();
    $down->fake(['sealer/v1/convert' => fn () => throw new ConnectionException('fora')]);
    app()->instance(SealerClient::class, new SealerClient($down, 'http://sealer', 't'));
    $v = wsUser();

    expect(wsDoc($v, 'MEDIADOCX', 'Outra.docx')->text)->toContain('indisponível')->and(Envelope::query()->where('tenant_id', $v->tenant_id)->count())->toBe(0);
});

it('a conversão do Word usa um prazo menor que o do job da fila', function () {
    $http = new HttpFactory();
    $http->fake(['sealer/v1/convert' => $http->response(WS_PDF)]);
    $client = new SealerClient($http, 'http://sealer', 't');

    $client->convert('PK docx', 40);

    $http->assertSent(fn ($r) => ($r->toPsrRequest()->getUri()->getPath() === '/v1/convert'));
    expect((new ReflectionMethod(SealerClient::class, 'convert'))->getNumberOfParameters())->toBe(2);
});

// ------------------------------------------------------------------ 5. gatilho

it('perguntas comuns não são confundidas com o pedido de enviar documento', function () {
    $u = wsUser();

    foreach (['quais documentos estão para assinatura?', 'qual o status do novo documento do João', 'como faço para enviar o documento?', 'o documento foi enviado ontem'] as $frase) {
        expect(wsText($u, $frase))->toBeNull();
    }
    foreach (['enviar documento', 'Quero enviar um documento', 'novo documento', 'preciso mandar um documento para assinatura'] as $frase) {
        expect(wsText($u, $frase)?->text)->toContain('PDF');
    }
});

it('com o grupo de envio desligado, uma pergunta de consulta segue para o assistente', function () {
    $u = wsUser();
    $u->tenant->forceFill(['assistant_tools' => ['consult']])->save();

    expect(wsText($u->fresh(), 'quais documentos estão para assinatura?'))->toBeNull()
        ->and(wsText($u->fresh(), 'enviar documento')->text)->toContain('não está liberado');
});

// ------------------------------------------------------------------ 6. trocar o arquivo não perde o progresso

it('descartar e trocar com falha no arquivo novo mantém o rascunho e o fluxo atuais', function () {
    $u = wsUser();
    wsDoc($u, name: 'Primeiro.pdf');
    wsDoc($u, 'MEDIACONN', 'Segundo.pdf'); // pergunta descartar/manter

    $r = wsBtn($u, 'flow_discard');

    expect($r->text)->toContain('baixar')->and(Envelope::query()->whereNull('trashed_at')->count())->toBe(1)
        ->and(Envelope::query()->whereNull('trashed_at')->value('title'))->toBe('Primeiro')
        ->and(app(FlowStore::class)->current($u)->step)->toBe('title');
});

// ------------------------------------------------------------------ 7. nomes e formatação

it('nome de arquivo enorme ou com lixo não derruba nada e não quebra a formatação', function () {
    $u = wsUser();
    $nome = "Contrato *importante* _v2_\x00\n".str_repeat('x', 400).'.pdf';

    $r = wsDoc($u, name: $nome);

    $e = Envelope::query()->first();
    expect($e)->not->toBeNull()->and(mb_strlen($e->document->name))->toBeLessThanOrEqual(150)->and($e->document->name)->not->toContain("\x00")
        ->and($e->title)->not->toContain('*')->and($e->title)->not->toContain('_')->and($r->text)->not->toContain('**');
});

it('o título digitado perde asteriscos, sublinhados e marcas de direção do texto', function () {
    $u = wsUser();
    wsDoc($u);

    wsText($u, "Contrato *final* _v3_ \u{202E}evil");

    expect(Envelope::query()->first()->title)->toBe('Contrato final v3 evil');
});

// ------------------------------------------------------------------ 9. botões de conversa antiga

it('os botões levam o carimbo do envio e botão de outro envio é recusado', function () {
    $u = wsUser();
    $r = wsDoc($u, name: 'Primeiro.pdf');
    $stamp = explode(':', $r->buttons[0]['id'])[1] ?? null;

    expect($stamp)->not->toBeNull()->and(strlen($stamp))->toBe(8);

    $stale = wsBtn($u, 'flow_title_keep:00000000');
    expect($stale->text)->toContain('antig')->and(app(FlowStore::class)->current($u)->step)->toBe('title');

    $ok = wsBtn($u, $r->buttons[0]['id']);
    expect(app(FlowStore::class)->current($u)->step)->toBe('signers')->and($ok->text)->toContain('Quem vai assinar');
});

// ------------------------------------------------------------------ 10. mesmo envio do site

it('mesmo que o rascunho tenha sido mexido no site (envio manual), o envio sai por e-mail como a mensagem diz', function () {
    $u = wsUser();
    wsToReview($u);
    Envelope::query()->first()->forceFill(['notify_channels' => ['manual']])->save();

    $r = wsBtn($u, 'flow_send');

    expect($r->text)->toContain('e-mail')->and(Envelope::query()->first()->notify_channels)->toBe(['email']);
    Mail::assertSent(SignatureRequestedMail::class, 1);
});

// ------------------------------------------------------------------ 12. e-mail com acento

it('e-mail com letra acentuada é recusado, não cortado em silêncio', function () {
    expect(SignerLine::parse('joão@gmail.com'))->toBeNull()
        ->and(SignerLine::parse('Maria maria@exámple.com'))->toBeNull()
        ->and(SignerLine::parse('João Silva joao@gmail.com'))->toBe(['name' => 'João Silva', 'email' => 'joao@gmail.com']);
});
