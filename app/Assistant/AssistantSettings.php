<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Models\Setting;

/** Configurações da plataforma para o assistente (o super administrador salva; os padrões vêm de config/assistant.php). */
final class AssistantSettings
{
    public function model(): string
    {
        $v = trim((string) Setting::get('assistant.model', ''));

        return $v !== '' ? $v : (string) config('assistant.model');
    }

    /** @return array<string,float> modelo => peso */
    public function modelWeights(): array
    {
        $raw = json_decode((string) Setting::get('assistant.model_weights', '{}'), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $model => $weight) {
            if (is_string($model) && is_numeric($weight) && (float) $weight > 0) {
                $out[$model] = (float) $weight;
            }
        }

        return $out;
    }

    public function modelWeight(string $model): float
    {
        return $this->modelWeights()[$model] ?? 1.0;
    }

    public function markupPercent(): float
    {
        return max(0.0, $this->float('assistant.markup_percent', (float) config('assistant.markup_percent')));
    }

    public function defaultPackageTokens(): int
    {
        return max(0, (int) $this->float('assistant.default_package_tokens', (float) config('assistant.default_package_tokens')));
    }

    public function overrunCap(): int
    {
        return max(0, (int) $this->float('assistant.overrun_cap', (float) config('assistant.overrun_cap')));
    }

    public function outputFactor(): float
    {
        return max(0.0, $this->float('assistant.output_factor', (float) config('assistant.output_factor')));
    }

    public function cachedFactor(): float
    {
        return max(0.0, $this->float('assistant.cached_factor', (float) config('assistant.cached_factor')));
    }

    public function purchaseValidityMonths(): int
    {
        return max(1, (int) $this->float('assistant.purchase_validity_months', (float) config('assistant.purchase_validity_months')));
    }

    private function float(string $key, float $default): float
    {
        $v = Setting::get($key);

        return $v !== null && $v !== '' && is_numeric($v) ? (float) $v : $default;
    }
}
