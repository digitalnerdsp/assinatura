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
