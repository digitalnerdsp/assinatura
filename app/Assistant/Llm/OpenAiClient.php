<?php

declare(strict_types=1);

namespace App\Assistant\Llm;

use App\Assistant\AssistantSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * API de conversa da OpenAI com chamada de funções. Um retry em erro 5xx, 429 ou falha de rede; qualquer outro erro
 * (chave inválida, pedido recusado) não é repetido. A chave nunca aparece em mensagens de erro.
 */
final class OpenAiClient implements LlmClient
{
    public function __construct(private readonly AssistantSettings $settings)
    {
    }

    public function chat(array $messages, array $toolSpecs, string $model): LlmResponse
    {
        $key = $this->settings->openAiApiKey();
        if ($key === '') {
            throw new LlmUnavailable('A chave da OpenAI não está configurada.');
        }

        $body = ['model' => $model, 'messages' => $messages, 'max_completion_tokens' => (int) config('assistant.max_output_tokens')];
        if ($toolSpecs !== []) {
            $body['tools'] = $toolSpecs;
            $body['tool_choice'] = 'auto';
        }

        $last = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::withToken($key)->acceptJson()->timeout(30)->post($this->settings->openAiBaseUrl().'/chat/completions', $body);
            } catch (ConnectionException) {
                $last = new LlmUnavailable('Falha de rede ao falar com a IA.');

                continue;
            }

            if ($response->successful()) {
                return $this->parse($response);
            }
            $status = $response->status();
            $last = new LlmUnavailable('A IA recusou a chamada (HTTP '.$status.').', $status);
            if ($status !== 429 && $status < 500) {
                break; // erro do pedido ou da chave: repetir não adianta
            }
        }

        throw $last ?? new LlmUnavailable('A IA não respondeu.');
    }

    private function parse(Response $response): LlmResponse
    {
        $message = $response->json('choices.0.message');
        if (! is_array($message)) {
            throw new LlmUnavailable('Resposta inesperada da IA.');
        }

        $calls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $c) {
            if (is_array($c) && isset($c['function']['name'])) {
                $calls[] = ['id' => (string) ($c['id'] ?? ''), 'name' => (string) $c['function']['name'], 'arguments' => (string) ($c['function']['arguments'] ?? '')];
            }
        }

        $usage = (array) $response->json('usage', []);

        return new LlmResponse(
            is_string($message['content'] ?? null) ? $message['content'] : null,
            $calls,
            ['input' => (int) ($usage['prompt_tokens'] ?? 0), 'cached' => (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0), 'output' => (int) ($usage['completion_tokens'] ?? 0)],
        );
    }
}
