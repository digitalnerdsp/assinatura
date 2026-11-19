<?php

declare(strict_types=1);

namespace App\Assistant\Pricing;

use App\Models\AssistantTokenEntry;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/** Números reais do uso da IA: conversa média, "≈ conversas" e o relatório mensal de custo por empresa. */
final class TokenEconomics
{
    private const TZ = 'America/Sao_Paulo';
    private const MIN_SAMPLE = 5;

    public function __construct(private readonly AssistantPricing $pricing)
    {
    }

    /** @return array{conversations:int,eq_tokens:int,avg_eq:?int,cost_brl:float,avg_cost_brl:?float} */
    public function conversationStats(int $days = 30): array
    {
        $row = AssistantTokenEntry::query()->where('kind', 'usage')->whereNotNull('conversation_id')->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('count(distinct conversation_id) as conversations, coalesce(-sum(tokens), 0) as eq_tokens, coalesce(sum(cost_brl), 0) as cost_brl')->first();

        $n = (int) $row->conversations;
        $eq = (int) $row->eq_tokens;
        $cost = round((float) $row->cost_brl, 6);

        return [
            'conversations' => $n, 'eq_tokens' => $eq, 'cost_brl' => $cost,
            'avg_eq' => $n > 0 ? (int) round($eq / $n) : null,
            'avg_cost_brl' => $n > 0 ? round($cost / $n, 6) : null,
        ];
    }

    /** Tokens equivalentes de uma conversa: a média medida (5 conversas ou mais) ou a estimativa da configuração. @return array{value:int,measured:bool} */
    public function tokensPerConversation(): array
    {
        $s = $this->conversationStats(30);
        if ($s['conversations'] >= self::MIN_SAMPLE && ($s['avg_eq'] ?? 0) > 0) {
            return ['value' => (int) $s['avg_eq'], 'measured' => true];
        }

        return ['value' => max(1, (int) config('assistant.est_tokens_per_conversation')), 'measured' => false];
    }

    public function conversationsLeft(int $tokens): int
    {
        return max(0, intdiv(max(0, $tokens), $this->tokensPerConversation()['value']));
    }

    /**
     * Uso do mês por empresa: tokens equivalentes, custo real (guardado na hora de cada resposta) e quanto isso valeria na venda.
     *
     * @return list<array{tenant_id:string,tenant:string,eq_tokens:int,cost_brl:float,sale_brl:?float}>
     */
    public function monthReport(string $period): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00', self::TZ)->utc();
        $end = $start->addMonth();
        $sale = $this->pricing->perMillion()['sale_brl'] ?? null;

        $rows = AssistantTokenEntry::query()->where('kind', 'usage')->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->selectRaw('tenant_id, coalesce(-sum(tokens), 0) as eq_tokens, coalesce(sum(cost_brl), 0) as cost_brl')->groupBy('tenant_id')->get();
        $names = Tenant::query()->whereIn('id', $rows->pluck('tenant_id'))->pluck('name', 'id');

        $out = $rows->map(fn ($r) => [
            'tenant_id' => (string) $r->tenant_id, 'tenant' => (string) ($names[$r->tenant_id] ?? '—'), 'eq_tokens' => (int) $r->eq_tokens,
            'cost_brl' => round((float) $r->cost_brl, 6), 'sale_brl' => $sale === null ? null : round((int) $r->eq_tokens / 1_000_000 * $sale, 6),
        ])->all();
        usort($out, fn ($a, $b) => [$b['cost_brl'], $a['tenant']] <=> [$a['cost_brl'], $b['tenant']]);

        return array_values($out);
    }
}
