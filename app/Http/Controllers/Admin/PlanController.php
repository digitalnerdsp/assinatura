<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Plans\PlanForm;
use App\Plans\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Planos (só super admin): limites, teste grátis, regras de consumo, recursos e preços por período. */
final class PlanController extends Controller
{
    public function __construct(private readonly PlanService $plans, private readonly PlanForm $form)
    {
    }

    public function index(): View
    {
        return view('admin.plans.index', [
            'plans' => Plan::query()->withCount('subscriptions')->with('prices')->orderBy('position')->orderBy('name')->get(),
            'noDefault' => ! Plan::query()->where('is_default', true)->where('active', true)->exists(),
        ]);
    }

    public function create(): View
    {
        $plan = new Plan(['active' => true, 'is_public' => true, 'features' => array_fill_keys(Plan::FEATURES, true)]);

        return view('admin.plans.form', ['plan' => $plan, 'prices' => collect()]);
    }

    public function edit(Plan $plan): View|RedirectResponse
    {
        if ($plan->isLegacy()) {
            return redirect()->route('admin.plans.index')->withErrors(['plan' => 'O plano Legado não pode ser editado: ele existe para manter as empresas antigas sem limite e sem cobrança.']);
        }

        return view('admin.plans.form', ['plan' => $plan, 'prices' => $plan->prices->keyBy('period')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $mapped = $this->form->plan($request->all());
        } catch (FlowException $e) {
            return back()->withErrors(['plan' => $e->getMessage()])->withInput();
        }

        DB::transaction(function () use ($data, $mapped) {
            $plan = Plan::query()->create($data + $mapped['attrs'] + ['slug' => $this->uniqueSlug($data['name'])]);
            $this->syncPrices($plan, $mapped['prices']);
        });

        return redirect()->route('admin.plans.index')->with('status', 'Plano criado.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        if ($plan->isLegacy()) {
            return back()->withErrors(['plan' => 'O plano Legado não pode ser editado.']);
        }
        $data = $this->validated($request);
        if ($plan->is_default && ! $data['active']) {
            return back()->withErrors(['plan' => 'Este é o plano padrão das empresas novas. Escolha outro plano como padrão antes de desativar este.'])->withInput();
        }

        try {
            $mapped = $this->form->plan($request->all());
        } catch (FlowException $e) {
            return back()->withErrors(['plan' => $e->getMessage()])->withInput();
        }

        DB::transaction(function () use ($plan, $data, $mapped) {
            $plan->update($data + $mapped['attrs']);
            $this->syncPrices($plan, $mapped['prices']);
        });

        return redirect()->route('admin.plans.index')->with('status', 'Plano atualizado. Vale para todas as empresas que o usam (menos onde há sobrescrita).');
    }

    public function makeDefault(Plan $plan): RedirectResponse
    {
        if ($plan->isLegacy() || ! $plan->active) {
            return back()->withErrors(['plan' => 'O plano padrão precisa estar ativo e não pode ser o Legado.']);
        }
        $this->plans->makeDefault($plan);

        return back()->with('status', "“{$plan->name}” agora é o plano das empresas novas.");
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->isLegacy()) {
            return back()->withErrors(['plan' => 'O plano Legado não pode ser apagado.']);
        }
        if ($plan->is_default) {
            return back()->withErrors(['plan' => 'Este é o plano padrão das empresas novas. Escolha outro plano como padrão antes de apagar ou desativar este.']);
        }
        if ($plan->subscriptions()->exists()) {
            $plan->update(['active' => false, 'is_default' => false, 'is_public' => false]);

            return back()->with('status', 'Há empresas neste plano, então ele foi desativado (não aparece mais nem é padrão) em vez de apagado.');
        }
        $plan->delete();

        return back()->with('status', 'Plano apagado.');
    }

    /** @return array{name:string,description:?string,active:bool,is_public:bool,position:int} */
    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        return [
            'name' => trim($d['name']), 'description' => $d['description'] ?? null,
            'position' => (int) ($d['position'] ?? 0),
            'active' => $request->boolean('active'), 'is_public' => $request->boolean('is_public'),
        ];
    }

    /** @param list<array<string,mixed>> $prices */
    private function syncPrices(Plan $plan, array $prices): void
    {
        PlanPrice::query()->where('plan_id', $plan->getKey())->delete();
        foreach ($prices as $row) {
            PlanPrice::query()->create(['plan_id' => $plan->getKey()] + $row);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plano';
        $slug = $base;
        for ($i = 2; $slug === 'legado' || Plan::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
