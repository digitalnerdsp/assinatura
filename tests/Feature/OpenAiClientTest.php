<?php

declare(strict_types=1);

use App\Assistant\AssistantSettings;
use App\Assistant\Llm\LlmUnavailable;
use App\Assistant\Llm\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    app(AssistantSettings::class)->saveOpenAi(['api_key' => 'sk-teste-123']);
});

const OA_URL = 'https://api.openai.com/v1/chat/completions';

function oaClient(): OpenAiClient
{
    return app(OpenAiClient::class);
}

function oaText(string $text = 'Olá!', int $in = 120, int $cached = 40, int $out = 15): array
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'prompt_tokens_details' => ['cached_tokens' => $cached]]];
}

const OA_TOOLS = [['type' => 'function', 'function' => ['name' => 'consultar', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => []]]]];

it('envia o modelo, as mensagens e as ferramentas com a chave no cabeçalho', function () {
    Http::fake([OA_URL => Http::response(oaText())]);

    oaClient()->chat([['role' => 'system', 'content' => 'regras'], ['role' => 'user', 'content' => 'oi']], OA_TOOLS, 'gpt-luna');

    Http::assertSent(fn (Request $r) => $r->url() === OA_URL && $r->hasHeader('Authorization', 'Bearer sk-teste-123')
        && $r['model'] === 'gpt-luna' && $r['messages'][1] === ['role' => 'user', 'content' => 'oi'] && $r['tools'] === OA_TOOLS && $r['tool_choice'] === 'auto');
});

it('sem ferramentas, não manda o campo de ferramentas', function () {
    Http::fake([OA_URL => Http::response(oaText())]);

    oaClient()->chat([['role' => 'user', 'content' => 'oi']], [], 'm');

    Http::assertSent(fn (Request $r) => ! isset($r['tools']) && ! isset($r['tool_choice']));
});

it('lê o texto e o uso de tokens, inclusive os de cache', function () {
    Http::fake([OA_URL => Http::response(oaText('Tudo certo', 120, 40, 15))]);

    $r = oaClient()->chat([['role' => 'user', 'content' => 'oi']], [], 'm');

    expect($r->text)->toBe('Tudo certo')->and($r->toolCalls)->toBe([])->and($r->usage)->toBe(['input' => 120, 'cached' => 40, 'output' => 15]);
});

it('lê os pedidos de função', function () {
    Http::fake([OA_URL => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
        ['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'consultar', 'arguments' => '{"status":"pending"}']],
    ]]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]])]);

    $r = oaClient()->chat([['role' => 'user', 'content' => 'oi']], OA_TOOLS, 'm');

    expect($r->text)->toBeNull()->and($r->toolCalls)->toBe([['id' => 'call_9', 'name' => 'consultar', 'arguments' => '{"status":"pending"}']])->and($r->usage['cached'])->toBe(0);
});

it('tenta uma segunda vez em erro 500, 429 ou 502 e então responde', function (int $status) {
    Http::fake([OA_URL => Http::sequence()->push(['error' => ['message' => 'falha']], $status)->push(oaText('depois do retry'))]);

    $r = oaClient()->chat([['role' => 'user', 'content' => 'oi']], [], 'm');

    expect($r->text)->toBe('depois do retry');
    Http::assertSentCount(2);
})->with([500, 429, 502]);

it('falha de rede também é tentada de novo uma vez', function () {
    Http::fake([OA_URL => Http::sequence()->pushFailedConnection('timeout')->push(oaText('ok'))]);

    expect(oaClient()->chat([['role' => 'user', 'content' => 'oi']], [], 'm')->text)->toBe('ok');
});

it('depois do único retry, o erro vira LlmUnavailable', function () {
    Http::fake([OA_URL => Http::response(['error' => ['message' => 'fora']], 503)]);

    expect(fn () => oaClient()->chat([['role' => 'user', 'content' => 'oi']], [], 'm'))->toThrow(LlmUnavailable::class);
    Http::assertSentCount(2);
});

it('erro 400 ou 401 não é tentado de novo', function (int $status) {
    Http::fake([OA_URL => Http::response(['error' => ['message' => 'ruim']], $status)]);

    expect(fn () => oaClient()->chat([['role' => 'user', 'content' => 'oi']], [], 'm'))->toThrow(LlmUnavailable::class);
    Http::assertSentCount(1);
})->with([400, 401, 404]);

it('sem chave da OpenAI configurada, não chama a rede', function () {
    \App\Models\Setting::query()->where('key', 'assistant.openai')->delete();
    Http::fake();

    expect(fn () => oaClient()->chat([['role' => 'user', 'content' => 'oi']], [], 'm'))->toThrow(LlmUnavailable::class, 'chave');
    Http::assertNothingSent();
});

it('resposta sem escolha ou com formato inesperado vira LlmUnavailable', function () {
    Http::fake([OA_URL => Http::response(['choices' => []])]);

    expect(fn () => oaClient()->chat([['role' => 'user', 'content' => 'oi']], [], 'm'))->toThrow(LlmUnavailable::class);
});

it('a chave fica cifrada no banco e em branco ao salvar mantém a anterior', function () {
    expect(\App\Models\Setting::get('assistant.openai'))->not->toContain('sk-teste-123');

    app(AssistantSettings::class)->saveOpenAi(['api_key' => '']);

    expect(app(AssistantSettings::class)->openAiApiKey())->toBe('sk-teste-123');
});

it('o erro de rede não vaza a chave na mensagem', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 6 ... Authorization: Bearer sk-teste-123'));

    try {
        oaClient()->chat([['role' => 'user', 'content' => 'oi']], [], 'm');
        $this->fail('esperava LlmUnavailable');
    } catch (LlmUnavailable $e) {
        expect($e->getMessage())->not->toContain('sk-teste-123');
    }
});
