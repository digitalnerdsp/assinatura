<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Assistant\Pricing\AssistantPricing;
use App\Assistant\Pricing\PackageOrders;
use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Models\AssistantPackage;
use App\Models\AssistantPackageOrder;
use App\Plans\PlanForm;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Pacotes adicionais de tokens (só super administrador): catálogo com preço, margem e a fila de pedidos das empresas. */
final class PackageController extends Controller
{
    public function index(AssistantPricing $pricing): View
    {
        return view('admin.packages', [
            'packages' => AssistantPackage::query()->orderBy('position')->orderBy('name')->get(),
            'pending' => AssistantPackageOrder::query()->with('tenant:id,name')->where('status', 'pending')->orderBy('created_at')->get(),
            'decided' => AssistantPackageOrder::query()->with('tenant:id,name')->where('status', '!=', 'pending')->orderByDesc('decided_at')->limit(15)->get(),
            'perMillion' => $pricing->perMillion(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        AssistantPackage::query()->create($this->data($request) + ['position' => (int) AssistantPackage::query()->max('position') + 1]);

        return redirect()->route('admin.packages')->with('status', 'Pacote criado.');
    }

    public function update(Request $request, AssistantPackage $package): RedirectResponse
    {
        $package->forceFill($this->data($request))->save();

        return redirect()->route('admin.packages')->with('status', 'Pacote salvo. Pedidos já feitos mantêm o preço e os tokens da hora do pedido.');
    }

    public function approve(Request $request, AssistantPackageOrder $order, PackageOrders $orders): RedirectResponse
    {
        try {
            $orders->approve($order, $request->user()->email);
        } catch (DomainException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return redirect()->route('admin.packages')->with('status', 'Pedido liberado: os tokens já estão na carteira da empresa.');
    }

    public function reject(Request $request, AssistantPackageOrder $order, PackageOrders $orders): RedirectResponse
    {
        $d = $request->validate(['note' => ['nullable', 'string', 'max:190']]);
        try {
            $orders->reject($order, $request->user()->email, $d['note'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return redirect()->route('admin.packages')->with('status', 'Pedido recusado.');
    }

    /** @return array{name:string,tokens:int,price_cents:int,validity_months:int,active:bool} */
    private function data(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'tokens' => ['required', 'string', 'max:15'],
            'price' => ['required', 'string', 'max:15'],
            'validity_months' => ['required', 'integer', 'between:1,60'],
        ], ['validity_months.between' => 'A validade vai de 1 a 60 meses.']);

        $tokens = str_replace(['.', ' '], '', $d['tokens']);
        if (! ctype_digit($tokens) || (int) $tokens < 1 || (int) $tokens > 1_000_000_000) {
            throw ValidationException::withMessages(['tokens' => 'Informe a quantidade de tokens (de 1 a 1.000.000.000).']);
        }
        try {
            $cents = PlanForm::money($d['price']);
        } catch (FlowException) {
            $cents = null;
        }
        if ($cents === null) {
            throw ValidationException::withMessages(['price' => 'Informe o preço em reais (ex.: 29,90).']);
        }

        return ['name' => trim($d['name']), 'tokens' => (int) $tokens, 'price_cents' => $cents, 'validity_months' => (int) $d['validity_months'], 'active' => $request->boolean('active')];
    }
}
