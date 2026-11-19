<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Assistant\Tools\Tool;
use App\Assistant\Tools\ToolResult;
use App\Models\User;
use Closure;

/** Ferramenta de teste: registra cada execução (usuário e argumentos recebidos). */
final class FakeTool implements Tool
{
    /** @var list<array{user:User,args:array<string,mixed>}> */
    public array $runs = [];

    /** @param array<string,mixed> $parameters */
    public function __construct(
        private readonly string $name = 'consultar',
        private readonly array $parameters = ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'enum' => ['pending', 'completed']], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]], 'required' => []],
        private readonly bool $confirm = false,
        private readonly ?Closure $result = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return 'Ferramenta de teste.';
    }

    public function parameters(): array
    {
        return $this->parameters;
    }

    public function needsConfirmation(): bool
    {
        return $this->confirm;
    }

    public function run(User $user, array $args): ToolResult
    {
        $this->runs[] = ['user' => $user, 'args' => $args];

        return $this->result ? ($this->result)($user, $args) : new ToolResult('2 documentos pendentes: Contrato A, Contrato B.');
    }
}
