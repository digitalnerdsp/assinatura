<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Assistant\Llm\LlmClient;
use App\Assistant\Llm\LlmResponse;
use App\Assistant\Llm\LlmUnavailable;

/** IA roteirizada para os testes: devolve as respostas na ordem e guarda o que recebeu. */
final class FakeLlmClient implements LlmClient
{
    /** @var list<array{messages:list<array<string,mixed>>,tools:list<array<string,mixed>>,model:string}> */
    public array $calls = [];

    /** @param list<LlmResponse|LlmUnavailable> $script */
    public function __construct(private array $script = [])
    {
    }

    public function chat(array $messages, array $toolSpecs, string $model): LlmResponse
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $toolSpecs, 'model' => $model];
        $next = array_shift($this->script) ?? LlmResponse::text('(sem roteiro)');
        if ($next instanceof LlmUnavailable) {
            throw $next;
        }

        return $next;
    }
}
