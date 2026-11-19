<?php

declare(strict_types=1);

namespace App\Assistant\Metering;

use App\Assistant\AssistantSettings;
use App\Models\AssistantTokenEntry;
use App\Models\Tenant;
use App\Plans\Entitlements;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Carteira de tokens da empresa. O pacote do plano vence no fim do mês; os extras comprados têm validade própria.
 * Débito: primeiro o pacote do plano, depois os extras que vencem antes. Cada débito grava de qual concessão saiu,
 * então concessão vencida nunca "devolve" nem "gasta" saldo de outra. O saldo só desce abaixo de zero até o limite de
 * excesso (uma conversa em andamento termina); o resto a plataforma absorve.
 */
final class TokenWallet
{
    private const TZ = 'America/Sao_Paulo';

    public function __construct(private readonly AssistantSettings $settings)
    {
    }

    public function balance(Tenant $tenant): int
    {
        $remaining = $this->grants($tenant)->sum(fn (array $g) => $g['remaining']);
        $overrun = (int) AssistantTokenEntry::query()->where('tenant_id', $tenant->getKey())->whereNull('source_id')->where('tokens', '<', 0)->sum('tokens');

        return (int) $remaining + $overrun;
    }

    public function canStart(Tenant $tenant): bool
    {
        return $this->balance($tenant) > 0;
    }

    /** Tokens do pacote do plano: nada sem o recurso; o limite do plano ou, se ele não definir, o padrão da plataforma. */
    public function planPackageFor(Tenant $tenant): int
    {
        $ent = Entitlements::for($tenant);
        if (! $ent->feature('assistant')) {
            return 0;
        }

        return $ent->limit('assistant_tokens') ?? $this->settings->defaultPackageTokens();
    }

    /** Concede o pacote do plano do mês atual (uma vez só por mês). */
    public function ensurePlanPackage(Tenant $tenant): void
    {
        $tokens = $this->planPackageFor($tenant);
        if ($tokens > 0) {
            $this->grantPlanPackage($tenant, $tokens, CarbonImmutable::now(self::TZ)->format('Y-m'));
        }
    }

    public function grantPlanPackage(Tenant $tenant, int $tokens, string $period): void
    {
        if ($tokens <= 0 || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            return;
        }
        if (AssistantTokenEntry::query()->where('tenant_id', $tenant->getKey())->where('kind', 'plan')->where('period', $period)->exists()) {
            return;
        }

        try {
            AssistantTokenEntry::query()->create([
                'tenant_id' => $tenant->getKey(), 'kind' => 'plan', 'tokens' => $tokens, 'period' => $period,
                'expires_on' => CarbonImmutable::createFromFormat('Y-m-d', $period.'-01', self::TZ)->endOfMonth()->toDateString(),
                'note' => 'Pacote do plano '.$period,
            ]);
        } catch (UniqueConstraintViolationException) {
            // outra execução concedeu ao mesmo tempo: já está feito
        }
    }

    public function addPurchase(Tenant $tenant, int $tokens, ?DateTimeInterface $expires, string $note): void
    {
        if ($tokens <= 0) {
            return;
        }
        AssistantTokenEntry::query()->create([
            'tenant_id' => $tenant->getKey(), 'kind' => 'purchase', 'tokens' => $tokens,
            'expires_on' => $expires?->format('Y-m-d'), 'note' => mb_substr($note, 0, 190),
        ]);
    }

    /** Ajuste manual do administrador (positivo concede, negativo desconta), sempre com nota. */
    public function adjust(Tenant $tenant, int $tokens, string $note): void
    {
        if ($tokens === 0) {
            return;
        }
        AssistantTokenEntry::query()->create(['tenant_id' => $tenant->getKey(), 'kind' => 'adjust', 'tokens' => $tokens, 'note' => mb_substr($note, 0, 190)]);
    }

    /** Tokens equivalentes de uma resposta da IA (peso do modelo, saída mais cara, entrada em cache mais barata). */
    public function equivalent(string $model, int $input, int $cached, int $output): int
    {
        $cached = min($cached, $input);
        $raw = ($input - $cached) + $cached * $this->settings->cachedFactor() + $output * $this->settings->outputFactor();

        return (int) ceil($raw * $this->settings->modelWeight($model));
    }

    /**
     * Debita o uso de uma resposta da IA. Devolve quantos tokens equivalentes foram efetivamente debitados
     * (menos que o uso quando o saldo chega ao piso do excesso).
     */
    public function debit(Tenant $tenant, string $model, int $input, int $cached, int $output, ?string $conversationId): int
    {
        $equivalent = $this->equivalent($model, $input, $cached, $output);
        if ($equivalent <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($tenant, $model, $input, $cached, $output, $conversationId, $equivalent): int {
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first(); // serializa os débitos da mesma empresa

            $charge = max(0, min($equivalent, $this->balance($tenant) + $this->settings->overrunCap()));
            if ($charge === 0) {
                return 0;
            }

            $left = $charge;
            $first = true;
            $row = function (?string $sourceId, int $tokens) use ($tenant, $model, $input, $cached, $output, $conversationId, &$first): void {
                AssistantTokenEntry::query()->create([
                    'tenant_id' => $tenant->getKey(), 'kind' => 'usage', 'tokens' => -$tokens, 'source_id' => $sourceId, 'model' => $model,
                    'input_tokens' => $first ? $input : 0, 'cached_tokens' => $first ? $cached : 0, 'output_tokens' => $first ? $output : 0,
                    'conversation_id' => $conversationId,
                ]);
                $first = false;
            };

            foreach ($this->grants($tenant)->sortBy(fn (array $g) => $g['order']) as $g) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, $g['remaining']);
                if ($take > 0) {
                    $row($g['id'], $take);
                    $left -= $take;
                }
            }
            if ($left > 0) {
                $row(null, $left); // excesso: sem concessão de origem
            }

            return $charge;
        });
    }

    /**
     * Concessões que ainda valem (não vencidas) com o que sobra de cada uma e a ordem de uso.
     *
     * @return Collection<int,array{id:string,remaining:int,order:string}>
     */
    private function grants(Tenant $tenant): Collection
    {
        $today = CarbonImmutable::now(self::TZ)->toDateString();

        return AssistantTokenEntry::query()
            ->where('tenant_id', $tenant->getKey())
            ->where(fn ($q) => $q->whereIn('kind', ['plan', 'purchase'])->orWhere(fn ($a) => $a->where('kind', 'adjust')->where('tokens', '>', 0)))
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', $today))
            ->withSum('consumed as used', 'tokens')
            ->get()
            ->map(fn (AssistantTokenEntry $e) => [
                'id' => (string) $e->getKey(),
                'remaining' => max(0, (int) $e->tokens + (int) $e->used),
                // plano primeiro; depois quem vence antes (sem validade por último); empate pelo mais antigo
                'order' => ($e->kind === 'plan' ? '0' : '1').'|'.($e->expires_on?->toDateString() ?? '9999-12-31').'|'.$e->getKey(),
            ]);
    }
}
