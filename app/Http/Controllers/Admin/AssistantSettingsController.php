<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Assistant\AssistantSettings;
use App\Assistant\Metering\MetaUsageLedger;
use App\Assistant\Metering\TokenWallet;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Configuração da plataforma para o assistente (só super administrador): credenciais, modelo, pesos, acréscimo e lançamentos manuais. */
final class AssistantSettingsController extends Controller
{
    public function __construct(private readonly AssistantSettings $settings, private readonly TokenWallet $wallet, private readonly MetaUsageLedger $ledger)
    {
    }

    public function edit(): View
    {
        return view('admin.assistant', [
            'status' => $this->settings->metaStatus() + ['openai' => $this->settings->openAiApiKey() !== ''],
            'phoneNumberId' => $this->settings->metaPhoneNumberId(),
            'displayPhone' => $this->settings->displayPhone(),
            'graphVersion' => $this->settings->graphVersion(),
            'model' => $this->settings->model(),
            'weights' => $this->lines($this->settings->modelWeights()),
            'rates' => $this->lines($this->settings->metaRates()),
            's' => $this->settings,
            'webhookUrl' => route('meta.webhook'),
            'tenants' => Tenant::query()->orderBy('name')->limit(300)->get(['id', 'name']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'access_token' => ['nullable', 'string', 'max:500'], 'app_secret' => ['nullable', 'string', 'max:500'], 'verify_token' => ['nullable', 'string', 'max:500'], 'api_key' => ['nullable', 'string', 'max:500'],
            'phone_number_id' => ['nullable', 'string', 'max:80'], 'display_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\s-]*$/'],
            'graph_version' => ['nullable', 'regex:/^v\d{1,2}\.\d{1,2}$/'], 'waba_id' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'], 'model_weights' => ['nullable', 'string', 'max:2000'], 'meta_rates' => ['nullable', 'string', 'max:2000'],
            'markup_percent' => ['required', 'numeric', 'between:0,500'], 'default_package_tokens' => ['required', 'integer', 'between:0,1000000000'],
            'overrun_cap' => ['required', 'integer', 'between:0,1000000000'], 'output_factor' => ['required', 'numeric', 'between:0,100'],
            'cached_factor' => ['required', 'numeric', 'between:0,1'], 'purchase_validity_months' => ['required', 'integer', 'between:1,60'],
            'currency' => ['required', 'regex:/^[A-Za-z]{3}$/'],
            'reasoning_effort' => ['nullable', 'in:none,minimal,low,medium,high'],
        ], [
            'markup_percent.between' => 'O acréscimo deve ficar entre 0% e 500%.', 'currency.regex' => 'A moeda tem 3 letras (ex.: BRL).',
            'display_phone.regex' => 'Use só números, espaço, +, ( ) e -.', 'graph_version.regex' => 'Use o formato v21.0.',
        ]);

        $weights = $this->parsePairs((string) ($d['model_weights'] ?? ''), 'model_weights', mustBePositive: true);
        $rates = $this->parsePairs((string) ($d['meta_rates'] ?? ''), 'meta_rates', mustBePositive: false);

        $this->settings->saveMeta(array_intersect_key($d, array_flip(['access_token', 'app_secret', 'verify_token', 'phone_number_id', 'display_phone', 'graph_version', 'waba_id'])));
        $this->settings->saveOpenAi(['api_key' => $d['api_key'] ?? '']);

        Setting::put('assistant.model', trim((string) ($d['model'] ?? '')));
        Setting::put('assistant.model_weights', json_encode($weights));
        Setting::put('assistant.meta_rates', json_encode($rates));
        Setting::put('assistant.markup_percent', (string) $d['markup_percent']);
        Setting::put('assistant.default_package_tokens', (string) $d['default_package_tokens']);
        Setting::put('assistant.overrun_cap', (string) $d['overrun_cap']);
        Setting::put('assistant.output_factor', (string) $d['output_factor']);
        Setting::put('assistant.cached_factor', (string) $d['cached_factor']);
        Setting::put('assistant.purchase_validity_months', (string) $d['purchase_validity_months']);
        Setting::put('assistant.currency', strtoupper($d['currency']));
        if ($request->has('reasoning_effort')) {
            Setting::put('assistant.reasoning_effort', (string) ($d['reasoning_effort'] ?? '')); // vazio = não enviar
        }

        app(\App\Assistant\Channel\StatusReconciler::class)->run(); // avisos que esperavam preço

        return redirect()->route('admin.assistant')->with('status', 'Configuração do assistente salva.');
    }

    /** Lançamento manual: pacote comprado, ajuste de saldo ou repasse da Meta. Tudo fica registrado com nota. */
    public function entry(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'kind' => ['required', 'in:purchase,adjust,meta'],
            'tokens' => ['required_if:kind,purchase,adjust', 'nullable', 'integer', 'between:-1000000000,1000000000'],
            'amount' => ['required_if:kind,meta', 'nullable', 'string', 'max:20'],
            'note' => ['required', 'string', 'max:190'],
        ], ['note.required' => 'Escreva uma nota: ela fica registrada no lançamento.', 'tokens.required_if' => 'Informe a quantidade de tokens.']);

        $tenant = Tenant::query()->findOrFail($d['tenant_id']);
        $note = trim($d['note']).' (por '.$request->user()->email.')';

        if ($d['kind'] === 'meta') {
            $amount = $this->money((string) $d['amount']);
            if ($amount === null || $amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero (ex.: 12,50).']);
            }
            $this->ledger->record($tenant, 'manual:'.Str::uuid(), 'manual', true, $amount, $this->settings->currency(), now());
        } else {
            $tokens = (int) $d['tokens'];
            if ($tokens === 0 || ($d['kind'] === 'purchase' && $tokens < 0)) {
                throw ValidationException::withMessages(['tokens' => $d['kind'] === 'purchase' ? 'Um pacote comprado precisa ter tokens positivos.' : 'O ajuste não pode ser zero.']);
            }
            $d['kind'] === 'purchase'
                ? $this->wallet->addPurchase($tenant, $tokens, now()->addMonths($this->settings->purchaseValidityMonths()), $note)
                : $this->wallet->adjust($tenant, $tokens, $note);
        }

        return redirect()->route('admin.assistant')->with('status', 'Lançamento registrado.');
    }

    /** @param array<string,float|int> $map */
    private function lines(array $map): string
    {
        return collect($map)->map(fn ($v, $k) => $k.'='.rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.'))->implode("\n");
    }

    /** "nome=valor" por linha. @return array<string,float> */
    private function parsePairs(string $text, string $field, bool $mustBePositive): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('=', $line, 2);
            $key = trim($parts[0]);
            $value = isset($parts[1]) ? str_replace(',', '.', trim($parts[1])) : '';
            if ($key === '' || ! preg_match('/^[\w.\-:]{1,80}$/', $key) || ! is_numeric($value) || (float) $value < 0 || ($mustBePositive && (float) $value <= 0)) {
                throw ValidationException::withMessages([$field => "Linha inválida: \"{$line}\". Use nome=valor".($mustBePositive ? ' (valor maior que zero).' : ' (valor zero ou maior).')]);
            }
            $out[$key] = (float) $value;
        }

        return $out;
    }

    private function money(string $v): ?float
    {
        $v = trim(str_replace(['R$', ' '], '', $v));
        $v = str_contains($v, ',') ? str_replace(['.', ','], ['', '.'], $v) : $v;

        return is_numeric($v) ? round((float) $v, 6) : null;
    }
}
