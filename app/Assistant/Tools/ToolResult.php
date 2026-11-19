<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

/** Resultado de uma ferramenta: texto curto para a IA e, opcionalmente, uma lista interativa para o usuário. */
final readonly class ToolResult
{
    /**
     * @param  array<string,mixed>  $data
     * @param  array{body:string,button:string,rows:list<array{id:string,title:string,description?:string}>}|null  $list
     */
    public function __construct(public string $text, public array $data = [], public ?array $list = null)
    {
    }
}
