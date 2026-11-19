<?php

declare(strict_types=1);

use App\Assistant\AssistantSettings;
use App\Assistant\Llm\LlmUnavailable;
use App\Assistant\Llm\OpenAiClient;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(AssistantSettings::class)->saveOpenAi(['api_key' => 'sk-segredo-123456']);
    config(['assistant.openai_url' => 'https://api.openai.com/v1']);
});

it('o erro da OpenAI traz o motivo que ela devolveu, sem a chave, e fica gravado para a tela', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => "The model `gpt-5.6-luna` does not exist or you do not have access to it. sk-segredo-123456", 'code' => 'model_not_found']], 400)]);

    try {
        app(OpenAiClient::class)->chat([['role' => 'user', 'content' => 'oi']], [], 'gpt-5.6-luna');
        $this->fail('deveria lançar');
    } catch (LlmUnavailable $e) {
        expect($e->getMessage())->toContain('HTTP 400')->toContain('does not exist')->not->toContain('sk-segredo');
    }

    $last = app(AssistantSettings::class)->lastError('openai');
    expect($last['message'])->toContain('does not exist')->and($last['message'])->not->toContain('sk-segredo')->and($last['at'])->not->toBeNull();
});

it('a tela da plataforma mostra o último erro da OpenAI e da Meta', function () {
    app(AssistantSettings::class)->recordError('openai', 'A IA recusou a chamada (HTTP 400): modelo inexistente');
    app(AssistantSettings::class)->recordError('meta', 'A Meta recusou a chamada: Authentication Error');
    $super = \App\Models\User::create(['tenant_id' => \App\Models\Tenant::create(['name' => 'P'])->id, 'name' => 'S', 'email' => 's@e.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $super->forceFill(['platform_role' => 'super_admin'])->save();

    $this->actingAs($super)->get(route('admin.assistant'))->assertOk()->assertSee('modelo inexistente')->assertSee('Authentication Error');
});

it('um envio que dá certo limpa o último erro daquele serviço', function () {
    app(AssistantSettings::class)->recordError('meta', 'falha antiga');
    app(AssistantSettings::class)->clearError('meta');

    expect(app(AssistantSettings::class)->lastError('meta'))->toBeNull();
});

it('a mensagem de erro é cortada e nunca grava o texto de uma chave', function () {
    app(AssistantSettings::class)->recordError('openai', str_repeat('x', 900));

    expect(mb_strlen(app(AssistantSettings::class)->lastError('openai')['message']))->toBeLessThanOrEqual(300);
});

// ------------------------------------------------------------------ esforço de raciocínio (modelos que raciocinam + funções)

it('manda reasoning_effort "none" por padrão, para modelos que raciocinam poderem usar funções', function () {
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]], 'usage' => []])]);

    app(OpenAiClient::class)->chat([['role' => 'user', 'content' => 'oi']], [['type' => 'function', 'function' => ['name' => 'x', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => new stdClass]]]], 'gpt-5.6-luna');

    Http::assertSent(fn ($r) => ($r->data()['reasoning_effort'] ?? null) === 'none');
});

it('o esforço configurado vale; em branco não envia o campo', function () {
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]], 'usage' => []])]);

    Setting::put('assistant.reasoning_effort', 'low');
    app(OpenAiClient::class)->chat([['role' => 'user', 'content' => 'oi']], [], 'm');
    Http::assertSent(fn ($r) => ($r->data()['reasoning_effort'] ?? null) === 'low');

    Setting::put('assistant.reasoning_effort', '');
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]], 'usage' => []])]);
    app(OpenAiClient::class)->chat([['role' => 'user', 'content' => 'oi']], [], 'm');
    Http::assertSent(fn ($r) => ! array_key_exists('reasoning_effort', $r->data()));
});

it('modelo que não aceita o campo: repete sem ele, uma vez', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(['error' => ['message' => "Unsupported parameter: 'reasoning_effort' is not supported with this model."]], 400)
        ->push(['choices' => [['message' => ['content' => 'ok']]], 'usage' => []])]);

    $r = app(OpenAiClient::class)->chat([['role' => 'user', 'content' => 'oi']], [], 'gpt-4o-mini');

    expect($r->text)->toBe('ok');
    Http::assertSentCount(2);
    $sent = Http::recorded();
    expect($sent[0][0]->data())->toHaveKey('reasoning_effort')->and($sent[1][0]->data())->not->toHaveKey('reasoning_effort');
});

it('a tela salva o esforço de raciocínio e recusa valor inventado', function () {
    $super = \App\Models\User::create(['tenant_id' => \App\Models\Tenant::create(['name' => 'P'])->id, 'name' => 'S', 'email' => 's2@e.test', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $super->forceFill(['platform_role' => 'super_admin'])->save();
    $base = ['markup_percent' => '10', 'default_package_tokens' => '1000', 'overrun_cap' => '10', 'output_factor' => '4', 'cached_factor' => '0.1', 'purchase_validity_months' => '12', 'currency' => 'BRL'];

    $this->actingAs($super)->put(route('admin.assistant.update'), $base + ['reasoning_effort' => 'low'])->assertRedirect();
    expect(app(AssistantSettings::class)->reasoningEffort())->toBe('low');

    $this->actingAs($super)->put(route('admin.assistant.update'), $base + ['reasoning_effort' => 'nuclear'])->assertSessionHasErrors('reasoning_effort');
});
