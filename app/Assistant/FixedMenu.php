<?php

declare(strict_types=1);

namespace App\Assistant;

/** Consultas rápidas que não usam IA (e por isso funcionam sem saldo de tokens ou com a IA fora do ar). */
final class FixedMenu
{
    /** @var array<string,array{tool:string,args:array<string,mixed>}> */
    public const ACTIONS = [
        'menu_pendentes' => ['tool' => 'list_documents', 'args' => ['status' => 'pending']],
        'menu_concluidos' => ['tool' => 'list_documents', 'args' => ['status' => 'completed']],
        'menu_contratos' => ['tool' => 'list_contracts_due', 'args' => ['within_days' => 30]],
    ];

    /** @return list<array{id:string,title:string}> */
    public static function buttons(): array
    {
        return [['id' => 'menu_pendentes', 'title' => 'Pendentes'], ['id' => 'menu_concluidos', 'title' => 'Concluídos'], ['id' => 'menu_contratos', 'title' => 'Contratos']];
    }
}
