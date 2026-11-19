<?php

declare(strict_types=1);

namespace App\Assistant\Tools;

use App\Contracts\ContractWatch;
use App\Models\User;
use App\Plans\Entitlements;

/** Contratos vencidos e os que vencem nos próximos dias (usa o acompanhamento de vigência; exige o recurso de relatórios do plano). */
final class ListContractsDueTool extends ReadOnlyTool
{
    private const SHOW = 10;

    public function __construct(private readonly ContractWatch $watch)
    {
    }

    public function name(): string
    {
        return 'list_contracts_due';
    }

    public function description(): string
    {
        return 'Lista os contratos vencidos e os que vencem nos próximos "within_days" dias (padrão 30), do mais urgente ao menos.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['within_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365]]];
    }

    public function run(User $user, array $args): ToolResult
    {
        if (! Entitlements::for($user->tenant)->feature('reports')) {
            return new ToolResult('O plano da sua empresa não inclui o acompanhamento de vencimento de contratos.');
        }

        $within = max(1, min(365, (int) ($args['within_days'] ?? 30)));
        $today = $this->watch->today();
        $query = $this->watch->monitored($user->tenant)->whereRaw('contract_expires_on <= cast(? as date) + cast(? as integer)', [$today->toDateString(), $within])
            ->orderBy('contract_expires_on')->orderByRaw('lower(title)')->orderBy('id');

        $total = (clone $query)->count();
        if ($total === 0) {
            return new ToolResult("Nenhum contrato vencido ou vencendo nos próximos {$within} dias.");
        }

        $lines = $query->limit(self::SHOW)->get()->values()->map(function ($e, int $i) use ($today) {
            $left = (int) $this->watch->daysLeft($e, $today);
            $when = match (true) {
                $left < 0 => 'venceu há '.abs($left).' dia'.(abs($left) === 1 ? '' : 's'),
                $left === 0 => 'vence hoje',
                default => "vence em {$left} dia".($left === 1 ? '' : 's'),
            };

            return ($i + 1).'. '.$this->quote($e->title).' — '.$when.' ('.$e->contract_expires_on->format('d/m/Y').')';
        })->implode("\n");

        $more = $total > self::SHOW ? "\n…e mais ".($total - self::SHOW).' contratos. Veja todos no painel, em Contratos.' : '';

        return new ToolResult("Contratos vencidos ou vencendo em até {$within} dias:\n".$lines.$more, ['count' => $total]);
    }
}
