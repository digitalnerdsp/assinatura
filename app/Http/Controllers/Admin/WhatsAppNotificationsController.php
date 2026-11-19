<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Assistant\AssistantSettings;
use App\Assistant\Channel\MetaException;
use App\Assistant\Notifications\NotificationCatalog;
use App\Assistant\Notifications\TemplateStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Tela do super administrador: o modelo de mensagem de cada aviso do WhatsApp, o envio para a Meta aprovar e o status. */
final class WhatsAppNotificationsController extends Controller
{
    public function edit(): View
    {
        return view('admin.notifications', [
            'catalog' => NotificationCatalog::all(),
            'templates' => app(TemplateStore::class)->all(),
            'hasWaba' => app(AssistantSettings::class)->wabaId() !== '',
            'stalledDays' => app(AssistantSettings::class)->stalledDays(),
            'tenants' => \App\Models\Tenant::query()->orderBy('name')->limit(300)->get(),
            'balances' => \App\Models\WhatsAppCreditEntry::query()->selectRaw('tenant_id, sum(amount) as total')->groupBy('tenant_id')->pluck('total', 'tenant_id'),
            'entries' => \App\Models\WhatsAppCreditEntry::query()->whereIn('kind', ['topup', 'adjust', 'settle'])->orderByDesc('created_at')->limit(15)->get(),
        ]);
    }

    /** Dias sem movimentação para o documento contar como parado. */
    public function settings(Request $request): RedirectResponse
    {
        $d = $request->validate(['stalled_days' => ['required', 'integer', 'between:1,90']], ['stalled_days.between' => 'Use de 1 a 90 dias.']);
        \App\Models\Setting::put('assistant.stalled_days', (string) $d['stalled_days']);

        return redirect()->route('admin.notifications')->with('status', 'Configuração salva.');
    }

    /** Modo de cobrança da empresa: pré-pago (só com saldo) ou pós-pago (até o limite, quitado na mensalidade). */
    public function billing(Request $request, \App\Models\Tenant $tenant): RedirectResponse
    {
        $raw = trim((string) $request->input('wa_credit_limit', ''));
        $request->merge(['wa_credit_limit' => $raw === '' ? null : ($this->money($raw) ?? 'inválido')]);
        $d = $request->validate([
            'wa_billing_mode' => ['required', 'in:prepaid,postpaid'],
            'wa_credit_limit' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ], ['wa_billing_mode.in' => 'Escolha pré-pago ou pós-pago.', 'wa_credit_limit.min' => 'O limite não pode ser negativo.', 'wa_credit_limit.numeric' => 'Informe o limite em reais (ex.: 50,00).', 'wa_credit_limit.max' => 'O limite máximo é R$ 1.000.000,00.']);

        $tenant->forceFill(['wa_billing_mode' => $d['wa_billing_mode'], 'wa_credit_limit' => $d['wa_billing_mode'] === 'postpaid' ? ($d['wa_credit_limit'] ?? 0) : null])->save();

        return redirect()->route('admin.notifications')->with('status', 'Cobrança de '.$tenant->name.' atualizada.');
    }

    /** Lançamento no saldo em reais: recarga, ajuste (+/−) ou quitação do pós-pago. Sempre com nota. */
    public function credit(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'kind' => ['required', 'in:topup,adjust,settle'],
            'amount' => ['required', 'string', 'max:20'],
            'note' => ['required', 'string', 'max:190'],
        ], ['note.required' => 'Escreva uma nota: ela fica registrada no lançamento.']);

        $amount = $this->money($d['amount']);
        if ($amount === null || abs($amount) < 0.01 || ($d['kind'] !== 'adjust' && $amount < 0)) {
            throw ValidationException::withMessages(['amount' => $d['kind'] === 'adjust' ? 'Informe um valor diferente de zero (ex.: -2,50).' : 'Informe um valor maior que zero (ex.: 20,00).']);
        }
        if (abs($amount) > 1000000) {
            throw ValidationException::withMessages(['amount' => 'O valor máximo por lançamento é R$ 1.000.000,00.']);
        }

        $tenant = \App\Models\Tenant::query()->findOrFail($d['tenant_id']);
        $credit = app(\App\Assistant\Metering\WhatsAppCredit::class);
        $by = $request->user()->email;
        match ($d['kind']) {
            'topup' => $credit->topUp($tenant, $amount, trim($d['note']), $by),
            'settle' => $credit->settle($tenant, $amount, trim($d['note']), $by),
            default => $credit->adjust($tenant, $amount, trim($d['note']), $by),
        };

        return redirect()->route('admin.notifications')->with('status', 'Lançamento registrado.');
    }

    private function money(string $v): ?float
    {
        $v = trim(str_replace(['R$', ' '], '', $v));
        if (str_contains($v, ',')) {
            $v = str_replace(['.', ','], ['', '.'], $v); // 1.250,50
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $v) === 1) {
            $v = str_replace('.', '', $v); // 1.000 = mil
        }

        return is_numeric($v) ? round((float) $v, 6) : null;
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        abort_unless(NotificationCatalog::exists($key), 404);
        $d = $request->validate([
            'meta_name' => ['required', 'regex:/^[a-z0-9_]{1,100}$/'],
            'language' => ['required', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'],
            'body' => ['required', 'string', 'max:'.NotificationCatalog::MAX_BODY],
        ], [
            'meta_name.regex' => 'O nome do modelo usa só letras minúsculas, números e _ (sem espaço).',
            'language.regex' => 'Use o código do idioma, como pt_BR.',
        ]);
        if (($problem = NotificationCatalog::validateBody($key, $d['body'])) !== null) {
            throw ValidationException::withMessages(['body' => $problem]);
        }
        if (\App\Models\WhatsAppTemplate::query()->where('meta_name', $d['meta_name'])->where('language', $d['language'])->where('key', '!=', $key)->exists()) {
            throw ValidationException::withMessages(['meta_name' => 'Outra situação já usa este nome de modelo neste idioma.']);
        }

        app(TemplateStore::class)->update($key, $d + ['enabled' => $request->boolean('enabled')]);

        return redirect()->route('admin.notifications')->with('status', 'Modelo salvo.');
    }

    public function submit(string $key): RedirectResponse
    {
        abort_unless(NotificationCatalog::exists($key), 404);
        $store = app(TemplateStore::class);

        try {
            $store->submit($store->find($key));
        } catch (MetaException $e) {
            return redirect()->route('admin.notifications')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.notifications')->with('status', 'Modelo enviado para aprovação da Meta. Use "Atualizar status" para acompanhar.');
    }

    public function sync(): RedirectResponse
    {
        try {
            $n = app(TemplateStore::class)->sync();
        } catch (MetaException $e) {
            return redirect()->route('admin.notifications')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.notifications')->with('status', $n === 0 ? 'Nenhuma mudança de status.' : "{$n} modelo(s) atualizado(s).");
    }
}
