<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

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

    // ---------- credenciais da Meta (cifradas com a APP_KEY; segredo em branco ao salvar mantém o anterior) ----------

    private const META_SECRETS = ['access_token', 'app_secret', 'verify_token'];
    private const META_PLAIN = ['phone_number_id', 'graph_version', 'waba_id', 'display_phone'];

    /** @param array<string,?string> $in */
    public function saveMeta(array $in): void
    {
        $stored = json_decode((string) Setting::get('assistant.meta', '{}'), true);
        $stored = is_array($stored) ? $stored : [];

        foreach (self::META_SECRETS as $key) {
            $value = trim((string) ($in[$key] ?? ''));
            if ($value !== '') {
                $stored[$key] = Crypt::encryptString($value);
            }
        }
        foreach (self::META_PLAIN as $key) {
            $value = trim((string) ($in[$key] ?? ''));
            if ($value !== '') {
                $stored[$key] = mb_substr($value, 0, 80);
            }
        }

        Setting::put('assistant.meta', json_encode($stored));
    }

    /** @param array<string,?string> $in */
    public function saveOpenAi(array $in): void
    {
        $stored = json_decode((string) Setting::get('assistant.openai', '{}'), true);
        $stored = is_array($stored) ? $stored : [];
        $key = trim((string) ($in['api_key'] ?? ''));
        if ($key !== '') {
            $stored['api_key'] = Crypt::encryptString($key);
        }
        Setting::put('assistant.openai', json_encode($stored));
    }

    public function openAiApiKey(): string
    {
        $stored = json_decode((string) Setting::get('assistant.openai', '{}'), true);
        $cipher = is_array($stored) ? (string) ($stored['api_key'] ?? '') : '';
        if ($cipher === '') {
            return '';
        }
        try {
            return Crypt::decryptString($cipher);
        } catch (DecryptException) {
            return '';
        }
    }

    public function openAiBaseUrl(): string
    {
        return rtrim((string) config('assistant.openai_url'), '/');
    }

    public function metaAccessToken(): string
    {
        return $this->metaSecret('access_token');
    }

    public function metaAppSecret(): string
    {
        return $this->metaSecret('app_secret');
    }

    public function metaVerifyToken(): string
    {
        return $this->metaSecret('verify_token');
    }

    public function metaPhoneNumberId(): string
    {
        return $this->metaPlain('phone_number_id');
    }

    /** Número da plataforma para a pessoa mandar o código (só dígitos; vazio = não configurado). */
    public function displayPhone(): string
    {
        return preg_replace('/\D/', '', $this->metaPlain('display_phone')) ?? '';
    }

    public function graphVersion(): string
    {
        return $this->metaPlain('graph_version') ?: 'v21.0';
    }

    /** Tem o necessário para enviar mensagens (token e número)? */
    public function metaConfigured(): bool
    {
        return $this->metaAccessToken() !== '' && $this->metaPhoneNumberId() !== '';
    }

    /** Quais segredos já estão salvos (a tela mostra só "configurado", nunca o valor). @return array<string,bool> */
    public function metaStatus(): array
    {
        return [
            'access_token' => $this->metaAccessToken() !== '', 'app_secret' => $this->metaAppSecret() !== '', 'verify_token' => $this->metaVerifyToken() !== '',
            'phone_number_id' => $this->metaPhoneNumberId() !== '',
        ];
    }

    /**
     * Preço por categoria (a Meta informa a categoria e se cobra, mas não o valor; o valor vem desta tabela da plataforma,
     * conferida todo mês com a fatura real). @return array<string,float>
     */
    public function metaRates(): array
    {
        $raw = json_decode((string) Setting::get('assistant.meta_rates', '{}'), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $category => $amount) {
            if (is_string($category) && is_numeric($amount) && (float) $amount >= 0) {
                $out[$category] = (float) $amount;
            }
        }

        return $out;
    }

    /** Preço da categoria; null = a plataforma ainda não cadastrou (a mensagem fica para conciliar). */
    public function rateFor(string $category): ?float
    {
        return $this->metaRates()[$category] ?? null;
    }

    public function currency(): string
    {
        $c = strtoupper(trim((string) Setting::get('assistant.currency', 'BRL')));

        return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'BRL';
    }

    private function metaSecret(string $key): string
    {
        $stored = json_decode((string) Setting::get('assistant.meta', '{}'), true);
        $cipher = is_array($stored) ? (string) ($stored[$key] ?? '') : '';
        if ($cipher === '') {
            return '';
        }

        try {
            return Crypt::decryptString($cipher);
        } catch (DecryptException) {
            return ''; // APP_KEY mudou: salvar de novo
        }
    }

    private function metaPlain(string $key): string
    {
        $stored = json_decode((string) Setting::get('assistant.meta', '{}'), true);

        return is_array($stored) ? (string) ($stored[$key] ?? '') : '';
    }

    private function float(string $key, float $default): float
    {
        $v = Setting::get($key);

        return $v !== null && $v !== '' && is_numeric($v) ? (float) $v : $default;
    }
}
