<?php

declare(strict_types=1);

namespace App\Assistant;

/** O que o assistente devolve: texto e, se houver, botões ou uma lista interativa. */
final readonly class AssistantReply
{
    /**
     * @param  list<array{id:string,title:string}>  $buttons
     * @param  array{body:string,button:string,rows:list<array{id:string,title:string,description?:string}>}|null  $list
     */
    public function __construct(public string $text, public array $buttons = [], public ?array $list = null)
    {
    }
}
