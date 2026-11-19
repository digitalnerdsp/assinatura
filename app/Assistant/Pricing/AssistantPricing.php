<?php

declare(strict_types=1);

namespace App\Assistant\Pricing;

use App\Models\Setting;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Quanto vale de fato um token. Preço por 1 milhão de tokens (em dólar) de cada modelo, com data de vigência, mais câmbio,
 * margem e taxas. Um token equivalente é 1 token de entrada, sem cache, do modelo base: tudo é medido nessa régua.
 * Enquanto a tabela não é salva, nada é calculado aqui e a carteira usa os pesos manuais antigos.
 */
final class AssistantPricing
{
    private const KEY = 'assistant.pricing';

    /** Valores iniciais para preencher a tela (informados pelo dono; confirmar na conta da OpenAI). Não ligam a fórmula. */
    public function defaults(): array
    {
        return [
            'base_model' => 'gpt-6-luna', 'brl_per_usd' => 5.9, 'margin_percent' => 100.0, 'fee_percent' => 0.0,
            'prices' => [
                'gpt-6-luna' => ['input' => 0.10, 'cached' => 0.01, 'output' => 0.50],
                'gpt-5.6-luna' => ['input' => 0.20, 'cached' => null, 'output' => 1.20],
            ],
        ];
    }

    public function configured(): bool
    {
        return $this->data() !== null;
    }

    public function baseModel(): string
    {
        return (string) ($this->data()['base_model'] ?? '');
    }

    public function fx(): float
    {
        return (float) ($this->data()['brl_per_usd'] ?? 0);
    }

    public function fxAsOf(): ?string
    {
        return $this->data()['fx_as_of'] ?? null;
    }

    public function marginPercent(): float
    {
        return (float) ($this->data()['margin_percent'] ?? 0);
    }

    public function feePercent(): float
    {
        return (float) ($this->data()['fee_percent'] ?? 0);
    }

    /** @return array{input:float,cached:?float,output:float}|null o preço vigente na data (hoje, se omitida) */
    public function price(string $model, ?CarbonInterface $at = null): ?array
    {
        $day = ($at ?? CarbonImmutable::now())->toDateString();
        $found = null;
        foreach ($this->versions($model) as $v) {
            if ($v['since'] <= $day) {
                $found = $v;
            }
        }

        return $found === null ? null : ['input' => (float) $found['input'], 'cached' => $found['cached'] === null ? null : (float) $found['cached'], 'output' => (float) $found['output']];
    }

    /** @return list<array{since:string,input:float,cached:?float,output:float}> do mais antigo para o mais novo */
    public function versions(string $model): array
    {
        $list = (array) ($this->data()['models'][$model] ?? []);
        usort($list, fn ($a, $b) => strcmp((string) $a['since'], (string) $b['since']));

        return array_values($list);
    }

    /** @return list<string> */
    public function models(): array
    {
        return array_keys((array) ($this->data()['models'] ?? []));
    }

    /** Tokens equivalentes de uma resposta; null se o modelo (ou o base) não tem preço salvo. */
    public function equivalent(string $model, int $input, int $cached, int $output, ?CarbonInterface $at = null): ?int
    {
        $usd = $this->weightedUsd($model, $input, $cached, $output, $at);
        $base = $this->basePrice($at);
        if ($usd === null || $base === null) {
            return null;
        }

        return (int) ceil(round($usd / $base['input'], 6));
    }

    /** Custo da resposta em dólar; null se o modelo não tem preço. */
    public function costUsd(string $model, int $input, int $cached, int $output, ?CarbonInterface $at = null): ?float
    {
        $usd = $this->weightedUsd($model, $input, $cached, $output, $at);

        return $usd === null ? null : round($usd / 1_000_000, 8);
    }

    public function costBrl(float $usd, ?float $fx = null): float
    {
        return round($usd * ($fx ?? $this->fx()), 6);
    }

    /** Valor de 1 milhão de tokens equivalentes em reais: o custo e a venda (custo × margem × taxas). */
    public function perMillion(): ?array
    {
        $base = $this->basePrice();
        if ($base === null || $this->fx() <= 0) {
            return null;
        }
        $cost = round($base['input'] * $this->fx(), 6);

        return ['cost_brl' => $cost, 'sale_brl' => round($cost * (1 + $this->marginPercent() / 100) * (1 + $this->feePercent() / 100), 6)];
    }

    /** Custo máximo, em reais, de uma franquia de tokens equivalentes (se a empresa gastar tudo). Null sem tabela de preços. */
    public function franchiseCostBrl(int $tokens): ?float
    {
        $per = $this->perMillion();

        return $per === null ? null : round(max(0, $tokens) / 1_000_000 * $per['cost_brl'], 6);
    }

    /** Percentual da mensalidade acima do qual a franquia gera alerta no cadastro do plano (padrão 10%). */
    public function franchiseAlertPercent(): float
    {
        $v = Setting::get('assistant.franchise_alert_percent');

        return is_numeric($v) && (float) $v > 0 ? (float) $v : 10.0;
    }

    /**
     * Salva a tabela. Mudar o preço de um modelo cria uma versão nova (a de antes continua valendo para o passado);
     * modelo que não veio no formulário é mantido. Lança InvalidArgumentException com a explicação em português.
     *
     * @param  array{base_model:string,brl_per_usd:mixed,margin_percent:mixed,fee_percent:mixed,prices:list<array{model:string,input:mixed,cached:mixed,output:mixed}>}  $in
     */
    public function save(array $in): void
    {
        $fx = $this->num($in['brl_per_usd'] ?? null);
        $margin = $this->num($in['margin_percent'] ?? null);
        $fee = $this->num($in['fee_percent'] ?? null);
        if ($fx === null || $fx <= 0 || $fx > 1000) {
            throw new InvalidArgumentException('Informe o câmbio em reais por dólar (ex.: 5,90).');
        }
        if ($margin === null || $margin < 0 || $margin > 10000) {
            throw new InvalidArgumentException('A margem deve ficar entre 0% e 10.000%.');
        }
        if ($fee === null || $fee < 0 || $fee > 100) {
            throw new InvalidArgumentException('As taxas devem ficar entre 0% e 100%.');
        }

        $data = $this->data() ?? ['models' => []];
        $today = CarbonImmutable::now()->toDateString();
        foreach ($in['prices'] ?? [] as $row) {
            $model = trim((string) ($row['model'] ?? ''));
            if ($model === '') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9._:\-]{1,80}$/', $model) !== 1) {
                throw new InvalidArgumentException("Nome de modelo inválido: {$model}.");
            }
            $input = $this->num($row['input'] ?? null);
            $cached = $this->num($row['cached'] ?? null);
            $output = $this->num($row['output'] ?? null);
            if ($input === null || $input <= 0 || $output === null || $output < 0 || ($cached !== null && $cached < 0) || max($input, $output) > 100000) {
                throw new InvalidArgumentException("Preços de {$model}: informe entrada (maior que zero) e saída; o cache é opcional. Valores em dólar por 1 milhão de tokens.");
            }
            $new = ['input' => $input, 'cached' => $cached, 'output' => $output];
            $latest = $this->latest($data['models'][$model] ?? []);
            if ($latest === null || array_diff_assoc($new, array_intersect_key($latest, $new)) !== [] || $latest['cached'] !== $cached) {
                $list = (array) ($data['models'][$model] ?? []);
                // Mesmo dia: a versão do dia é substituída (não cria duas versões na mesma data).
                $list = array_values(array_filter($list, fn ($v) => $v['since'] !== $today));
                $list[] = ['since' => $today] + $new;
                $data['models'][$model] = $list;
            }
        }

        $base = trim((string) ($in['base_model'] ?? ''));
        if ($base === '' || ! isset($data['models'][$base])) {
            throw new InvalidArgumentException('Escolha como modelo base um modelo que tenha preço cadastrado.');
        }

        Setting::put(self::KEY, json_encode([
            'base_model' => $base, 'brl_per_usd' => $fx, 'fx_as_of' => $today, 'margin_percent' => $margin, 'fee_percent' => $fee, 'models' => $data['models'],
        ]));
    }

    // ---------------------------------------------------------------- interno

    /** @return array<string,mixed>|null */
    private function data(): ?array
    {
        $raw = json_decode((string) Setting::get(self::KEY, ''), true);

        return is_array($raw) && isset($raw['models'], $raw['base_model']) ? $raw : null;
    }

    private function basePrice(?CarbonInterface $at = null): ?array
    {
        $p = $this->configured() ? $this->price($this->baseModel(), $at) : null;

        return $p !== null && $p['input'] > 0 ? $p : null;
    }

    /** Soma em dólar por 1 milhão: (entrada sem cache × P_ent) + (cache × P_cache) + (saída × P_saída). Cache sem preço custa a entrada cheia. */
    private function weightedUsd(string $model, int $input, int $cached, int $output, ?CarbonInterface $at): ?float
    {
        $p = $this->configured() ? $this->price($model, $at) : null;
        if ($p === null) {
            return null;
        }
        $input = max(0, $input);
        $cached = min(max(0, $cached), $input);
        $output = max(0, $output);

        return (($input - $cached) * $p['input']) + ($cached * ($p['cached'] ?? $p['input'])) + ($output * $p['output']);
    }

    /** @param list<array<string,mixed>> $versions */
    private function latest(array $versions): ?array
    {
        usort($versions, fn ($a, $b) => strcmp((string) $a['since'], (string) $b['since']));

        return $versions === [] ? null : end($versions);
    }

    private function num(mixed $v): ?float
    {
        if ($v === null || trim((string) $v) === '') {
            return null;
        }

        return is_numeric($v) ? (float) $v : Money::reais((string) $v);
    }
}
