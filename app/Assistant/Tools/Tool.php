<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Models\User;

/**
 * Uma ação que o assistente pode executar. Quem executa é sempre o usuário vinculado, com as mesmas regras e limites de plano:
 * a IA nunca escolhe empresa nem usuário.
 */
interface Tool
{
    public function name(): string;

    public function description(): string;

    /** JSON Schema (type object) dos parâmetros. Propriedades que não estão aqui são descartadas antes de executar. @return array<string,mixed> */
    public function parameters(): array;

    /** Ações que mudam algo precisam de confirmação explícita. No Plano 1 só há consultas; ferramenta que pede confirmação não é executada. */
    public function needsConfirmation(): bool;

    /** @param array<string,mixed> $args já validados contra `parameters()` */
    public function run(User $user, array $args): ToolResult;
}
