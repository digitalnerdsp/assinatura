<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

/** Ferramentas registradas. O orquestrador só executa o que está aqui. */
final class ToolRegistry
{
    /** @var array<string,Tool> */
    private array $tools = [];

    /** @param list<Tool> $tools */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** Cópia só com as ferramentas de nome permitido. @param list<string> $names */
    public function only(array $names): self
    {
        return new self(array_values(array_filter($this->tools, fn (Tool $t) => in_array($t->name(), $names, true))));
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return list<Tool> */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /** Ferramentas no formato de função da OpenAI. Só as de consulta (sem confirmação) são oferecidas. @return list<array<string,mixed>> */
    public function specs(): array
    {
        $out = [];
        foreach ($this->tools as $tool) {
            if (! $tool->needsConfirmation()) {
                $out[] = ['type' => 'function', 'function' => ['name' => $tool->name(), 'description' => $tool->description(), 'parameters' => $tool->parameters()]];
            }
        }

        return $out;
    }
}
