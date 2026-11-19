<?php

declare(strict_types=1);

namespace App\Assistant\Llm;

/** Resposta de uma chamada à IA: texto ou pedidos de ferramenta, mais o uso de tokens (que alimenta a carteira). */
final readonly class LlmResponse
{
    /**
     * @param  list<array{id:string,name:string,arguments:string}>  $toolCalls  `arguments` é o JSON como a IA mandou
     * @param  array{input:int,cached:int,output:int}  $usage
     */
    public function __construct(public ?string $text, public array $toolCalls = [], public array $usage = ['input' => 0, 'cached' => 0, 'output' => 0])
    {
    }

    /** @param array{input?:int,cached?:int,output?:int} $usage */
    public static function text(string $text, array $usage = []): self
    {
        return new self($text, [], self::usage($usage));
    }

    /**
     * @param  list<array{id?:string,name:string,arguments:array<string,mixed>|string}>  $calls
     * @param  array{input?:int,cached?:int,output?:int}  $usage
     */
    public static function tools(array $calls, array $usage = [], ?string $text = null): self
    {
        $out = [];
        foreach ($calls as $i => $c) {
            $out[] = ['id' => $c['id'] ?? 'call_'.($i + 1), 'name' => $c['name'], 'arguments' => is_string($c['arguments']) ? $c['arguments'] : json_encode($c['arguments'])];
        }

        return new self($text, $out, self::usage($usage));
    }

    /** @param array{input?:int,cached?:int,output?:int} $u @return array{input:int,cached:int,output:int} */
    private static function usage(array $u): array
    {
        return ['input' => (int) ($u['input'] ?? 0), 'cached' => (int) ($u['cached'] ?? 0), 'output' => (int) ($u['output'] ?? 0)];
    }
}
