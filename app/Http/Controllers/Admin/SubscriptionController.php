<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\UsageEntry;
use App\Plans\Entitlements;
use App\Plans\PlanForm;
use App\Plans\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Assinaturas das empresas (admin e super admin): plano, situação, sobrescritas, créditos e extrato. */
final class SubscriptionController extends Controller
{
    public function __construct(private readonly PlanService $plans, private readonly PlanForm $form)
    {
    }

    public function index(Request $request): View
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'plan' => ['nullable', 'uuid'], 'status' => ['nullable', 'in:trialing,active,blocked,canceled']]);
        $like = isset($f['q']) ? '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($f['q'])).'%' : null;

        $subs = Subscription::query()->with(['plan:id,name', 'tenant:id,name'])
            ->when($f['plan'] ?? null, fn ($q, $v) => $q->where('plan_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($like, fn ($q) => $q->whereHas('tenant', fn ($t) => $t->whereRaw('lower(name) like ?', [$like])))
            ->latest()->paginate(20)->withQueryString();

        return view('admin.subscriptions.index', ['subs' => $subs, 'plans' => Plan::query()->orderBy('name')->get(['id', 'name']), 'filters' => $f]);
    }

    public function show(Tenant $tenant): View
    {
        $sub = $this->plans->subscriptionFor($tenant)->load('plan');

        return view('admin.subscriptions.show', [
            'tenant' => $tenant, 'sub' => $sub,
            'plans' => Plan::query()->where('active', true)->orWhere('id', $sub->plan_id)->orderBy('position')->orderBy('name')->get(),
            'effective' => $this->plans->effective($sub),
            'usage' => Entitlements::for($tenant)->usage(),
            'wa' => $this->waPanel($tenant),
            'entries' => UsageEntry::query()->where('tenant_id', $tenant->getKey())->latest('occurred_at')->limit(50)->get(),
        ]);
    }

    /** @return array{balance:float,reserved:float,limit:float,postpaid:bool,entries:\Illuminate\Support\Collection} */
    private function waPanel(Tenant $tenant): array
    {
        $credit = app(\App\Assistant\Metering\WhatsAppCredit::class);

        return [
            'balance' => $credit->balance($tenant), 'reserved' => $credit->reserved($tenant), 'limit' => $credit->limit($tenant), 'postpaid' => $credit->isPostpaid($tenant),
            'entries' => $credit->entries($tenant, 20),
        ];
    }

    /** Lançamento no saldo dos avisos do WhatsApp: recarga, ajuste (+/−) ou quitação do pós-pago. Só super administrador; sempre com nota. */
    public function waCredit(Request $request, Tenant $tenant): RedirectResponse
    {
        $d = $request->validate([
            'kind' => ['required', 'in:topup,adjust,settle'],
            'amount' => ['required', 'string', 'max:20'],
            'note' => ['required', 'string', 'max:190'],
        ], ['note.required' => 'Escreva uma nota: ela fica registrada no lançamento.']);

        $amount = \App\Support\Money::reais($d['amount']);
        if ($amount === null || abs($amount) < 0.01 || ($d['kind'] !== 'adjust' && $amount < 0)) {
            return back()->withErrors(['amount' => $d['kind'] === 'adjust' ? 'Informe um valor diferente de zero (ex.: -2,50).' : 'Informe um valor maior que zero (ex.: 20,00).'])->withInput();
        }
        if (abs($amount) > 1000000) {
            return back()->withErrors(['amount' => 'O valor máximo por lançamento é R$ 1.000.000,00.'])->withInput();
        }

        $credit = app(\App\Assistant\Metering\WhatsAppCredit::class);
        $note = trim($d['note']);
        $by = $request->user()->email;
        match ($d['kind']) {
            'topup' => $credit->topUp($tenant, $amount, $note, $by),
            'settle' => $credit->settle($tenant, $amount, $note, $by),
            default => $credit->adjust($tenant, $amount, $note, $by),
        };

        return back()->with('status', 'Lançamento registrado no saldo dos avisos.');
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $d = $request->validate([
            'plan_id' => ['required', 'uuid', 'exists:plans,id'],
            'status' => ['required', 'in:trialing,active,blocked,canceled'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $overrides = $this->form->overrides($request->all());
        } catch (FlowException $e) {
            return back()->withErrors(['plan' => $e->getMessage()])->withInput();
        }

        DB::transaction(function () use ($tenant, $d, $overrides, $request) {
            $sub = $this->plans->assign($tenant, Plan::query()->findOrFail($d['plan_id']));
            $sub->forceFill([
                'status' => $d['status'], 'billing_exempt' => $request->boolean('billing_exempt'),
                'notes' => $d['notes'] ?? null, 'overrides' => $overrides,
            ])->save();
        });

        return redirect()->route('admin.subscriptions.show', $tenant)->with('status', 'Assinatura atualizada.');
    }

    /** Lança crédito (ou desconto) manual no extrato, por exemplo um pagamento recebido fora do sistema. */
    public function credits(Request $request, Tenant $tenant): RedirectResponse
    {
        $d = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'note' => ['required', 'string', 'max:200'],
        ]);

        $error = DB::transaction(function () use ($tenant, $d): ?string {
            $this->plans->subscriptionFor($tenant);
            $sub = Subscription::query()->where('tenant_id', $tenant->getKey())->lockForUpdate()->firstOrFail();
            $new = $sub->credits_balance + (int) $d['delta'];
            if ($new < 0) {
                return "O saldo de créditos ({$sub->credits_balance}) não pode ficar negativo.";
            }
            $sub->update(['credits_balance' => $new]);
            UsageEntry::query()->create([
                'tenant_id' => $tenant->getKey(), 'kind' => 'adjust', 'delta' => (int) $d['delta'],
                'balance_after' => $new, 'note' => mb_substr(trim($d['note']), 0, 200), 'occurred_at' => now(),
            ]);

            return null;
        });

        return $error ? back()->withErrors(['delta' => $error]) : back()->with('status', 'Lançamento registrado no extrato.');
    }
}
