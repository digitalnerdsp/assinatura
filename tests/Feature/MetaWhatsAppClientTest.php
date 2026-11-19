<?php

declare(strict_types=1);

use App\Assistant\AssistantSettings;
use App\Assistant\Channel\MetaException;
use App\Assistant\Channel\MetaWhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    app(AssistantSettings::class)->saveMeta(['access_token' => 'EAAB-token', 'phone_number_id' => 'PNID123', 'app_secret' => 's', 'verify_token' => 'v', 'graph_version' => 'v21.0']);
});

function metaClient(): MetaWhatsAppClient
{
    return app(MetaWhatsAppClient::class);
}

it('envia texto com o token, o número da plataforma e o corpo certo', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

    $id = metaClient()->sendText('5511999990000', 'Olá!');

    expect($id)->toBe('wamid.X');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v21.0/PNID123/messages' && $r->hasHeader('Authorization', 'Bearer EAAB-token')
        && $r['messaging_product'] === 'whatsapp' && $r['to'] === '5511999990000' && $r['type'] === 'text' && $r['text']['body'] === 'Olá!');
});

it('corta texto acima do limite da Meta (4096)', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w']]], 200)]);

    metaClient()->sendText('5511999990000', str_repeat('a', 5000));

    Http::assertSent(fn (Request $r) => mb_strlen($r['text']['body']) === 4096);
});

it('envia botões de resposta (até 3, título até 20)', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w']]], 200)]);

    metaClient()->sendButtons('5511999990000', 'Escolha', [['id' => 'a', 'title' => 'Pendentes'], ['id' => 'b', 'title' => 'Concluídos']]);

    Http::assertSent(fn (Request $r) => $r['type'] === 'interactive' && $r['interactive']['type'] === 'button' && $r['interactive']['body']['text'] === 'Escolha'
        && $r['interactive']['action']['buttons'][1] === ['type' => 'reply', 'reply' => ['id' => 'b', 'title' => 'Concluídos']]);
});

it('recusa 4 botões ou título com mais de 20 caracteres antes de chamar a rede', function () {
    Http::fake();

    expect(fn () => metaClient()->sendButtons('5511999990000', 'x', array_map(fn ($i) => ['id' => "b$i", 'title' => 'T'], [1, 2, 3, 4])))->toThrow(InvalidArgumentException::class);
    expect(fn () => metaClient()->sendButtons('5511999990000', 'x', [['id' => 'a', 'title' => str_repeat('a', 21)]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => metaClient()->sendButtons('5511999990000', 'x', []))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('envia lista (até 10 linhas) e recusa mais que isso', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w']]], 200)]);
    $rows = array_map(fn ($i) => ['id' => "d$i", 'title' => "Documento $i", 'description' => 'Aguardando'], range(1, 10));

    metaClient()->sendList('5511999990000', 'Seus documentos', 'Ver', $rows);

    Http::assertSent(fn (Request $r) => $r['interactive']['type'] === 'list' && $r['interactive']['action']['button'] === 'Ver' && count($r['interactive']['action']['sections'][0]['rows']) === 10);
    expect(fn () => metaClient()->sendList('5511999990000', 'x', 'Ver', array_merge($rows, [['id' => 'd11', 'title' => 'x']])))->toThrow(InvalidArgumentException::class);
});

it('marca a mensagem como lida', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

    metaClient()->markRead('wamid.IN1');

    Http::assertSent(fn (Request $r) => $r['status'] === 'read' && $r['message_id'] === 'wamid.IN1');
});

it('erro da Meta vira MetaException com status e código', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Token expirado', 'code' => 190, 'error_subcode' => 463]], 401)]);

    try {
        metaClient()->sendText('5511999990000', 'x');
        $this->fail('esperava MetaException');
    } catch (MetaException $e) {
        expect($e->status)->toBe(401)->and($e->metaCode)->toBe(190)->and($e->getMessage())->toContain('Token expirado');
    }
});

it('falha de rede também vira MetaException (nunca vaza detalhes do token)', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: sem rede'));

    expect(fn () => metaClient()->sendText('5511999990000', 'x'))->toThrow(MetaException::class);
});

it('sem credenciais configuradas, não chama a rede e avisa', function () {
    \App\Models\Setting::query()->where('key', 'assistant.meta')->delete();
    Http::fake();

    expect(fn () => metaClient()->sendText('5511999990000', 'x'))->toThrow(MetaException::class, 'não configur');
    Http::assertNothingSent();
});

it('baixa a mídia em duas etapas: primeiro o endereço, depois o arquivo', function () {
    Http::fake([
        'graph.facebook.com/v21.0/MEDIA9' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', 'mime_type' => 'application/pdf'], 200),
        'lookaside.fbsbx.com/*' => Http::response('%PDF-1.4 conteudo', 200),
    ]);

    $media = metaClient()->downloadMedia('MEDIA9');

    expect($media)->toBe(['bytes' => '%PDF-1.4 conteudo', 'mime' => 'application/pdf']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'lookaside.fbsbx.com') && $r->hasHeader('Authorization', 'Bearer EAAB-token'));
});

it('recusa baixar mídia de endereço que não é da Meta (proteção contra desvio)', function () {
    Http::fake([
        'graph.facebook.com/v21.0/MEDIA9' => Http::response(['url' => 'http://169.254.169.254/latest/meta-data', 'mime_type' => 'application/pdf'], 200),
        '*' => Http::response('segredo', 200),
    ]);

    expect(fn () => metaClient()->downloadMedia('MEDIA9'))->toThrow(MetaException::class);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '169.254'));
});

it('envia arquivo: sobe a mídia e depois manda o documento', function () {
    Http::fake([
        'graph.facebook.com/v21.0/PNID123/media' => Http::response(['id' => 'MID77'], 200),
        'graph.facebook.com/v21.0/PNID123/messages' => Http::response(['messages' => [['id' => 'wamid.DOC']]], 200),
    ]);

    $mediaId = metaClient()->uploadMedia('%PDF-1.4 x', 'application/pdf');
    $msgId = metaClient()->sendDocument('5511999990000', $mediaId, 'assinado-contrato.pdf', 'Seu documento assinado');

    expect($mediaId)->toBe('MID77')->and($msgId)->toBe('wamid.DOC');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/media') && $r->hasHeader('Authorization', 'Bearer EAAB-token'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['type'] === 'document' && $r['document'] === ['id' => 'MID77', 'filename' => 'assinado-contrato.pdf', 'caption' => 'Seu documento assinado']);
});

it('as credenciais ficam cifradas no banco e o segredo em branco ao salvar mantém o anterior', function () {
    $raw = \App\Models\Setting::get('assistant.meta');
    expect($raw)->not->toContain('EAAB-token')->and($raw)->not->toContain('s"');

    app(AssistantSettings::class)->saveMeta(['access_token' => '', 'phone_number_id' => 'PNID999', 'app_secret' => '', 'verify_token' => '']);

    $s = app(AssistantSettings::class);
    expect($s->metaAccessToken())->toBe('EAAB-token')->and($s->metaPhoneNumberId())->toBe('PNID999')->and($s->metaAppSecret())->toBe('s');
});
