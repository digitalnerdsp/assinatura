<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Gestão de clientes. O admin vê metadados (contagens, títulos, status), nunca o conteúdo dos documentos. */
final class TenantController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $tenants = Tenant::query()
            ->withCount(['users', 'envelopes'])
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', "%{$q}%"))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.tenants.index', compact('tenants', 'q'));
    }

    public function show(Tenant $tenant): View
    {
        $monthStart = now()->startOfMonth();

        return view('admin.tenants.show', [
            'tenant' => $tenant->load('signingSetting'),
            'users' => $tenant->users()->orderBy('created_at')->get(['id', 'name', 'email', 'role', 'created_at']),
            'usedThisMonth' => Envelope::where('tenant_id', $tenant->getKey())->where('sent_at', '>=', $monthStart)->count(),
            'recent' => Envelope::where('tenant_id', $tenant->getKey())->latest()->limit(10)->get(['id', 'title', 'status', 'created_at']),
        ]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(['free', 'pro', 'enterprise'])],
            'monthly_envelope_limit' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $tenant->update([
            'plan' => $data['plan'],
            'monthly_envelope_limit' => $data['monthly_envelope_limit'] ?? null,
            'admin_notes' => $data['admin_notes'] ?? null,
        ]);

        return back()->with('status', 'Cliente atualizado.');
    }

    public function suspend(Tenant $tenant): RedirectResponse
    {
        $tenant->update(['suspended_at' => now()]);

        return back()->with('status', 'Conta suspensa. Os usuários serão desconectados e a API deixa de responder.');
    }

    public function reactivate(Tenant $tenant): RedirectResponse
    {
        $tenant->update(['suspended_at' => null]);

        return back()->with('status', 'Conta reativada.');
    }
}
