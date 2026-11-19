<?php

declare(strict_types=1);

namespace App\Assistant\Llm;

/** A IA atrás de uma interface: o orquestrador não sabe qual fornecedor responde. */
interface LlmClient
{
    /**
     * @param  list<array<string,mixed>>  $messages  formato de chat (system, user, assistant com tool_calls, tool)
     * @param  list<array<string,mixed>>  $toolSpecs  ferramentas no formato de função (name, description, parameters)
     *
     * @throws LlmUnavailable
     */
    public function chat(array $messages, array $toolSpecs, string $model): LlmResponse;
}
