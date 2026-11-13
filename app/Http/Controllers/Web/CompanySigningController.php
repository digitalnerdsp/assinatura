<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TenantSigningSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Assinatura da empresa": política do remetente, representante e termo de delegação (assinatura pela API).
 * Só o dono da conta altera.
 */
final class CompanySigningController extends Controller
{
    public function edit(Request $request): View
    {
        $setting = TenantSigningSetting::query()->where('tenant_id', $request->user()->tenant_id)->first() ?? new TenantSigningSetting();

        return view('company.signing', [
            'setting' => $setting,
            'policy' => $setting->sender_policy ?: 'always',
            'isOwner' => $request->user()->role === 'owner',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'owner', 403);

        $data = $request->validate([
            'sender_policy' => ['required', 'in:always,optional,never'],
            'representative_name' => ['nullable', 'string', 'max:150'],
            'representative_email' => ['nullable', 'email', 'max:190'],
            'representative_cpf' => ['nullable', 'string', 'max:14'],
            'accept_delegation' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $setting = TenantSigningSetting::firstOrNew(['tenant_id' => $user->tenant_id]);

        $new = [
            'name' => trim((string) ($data['representative_name'] ?? '')),
            'email' => mb_strtolower(trim((string) ($data['representative_email'] ?? ''))),
            'cpf' => preg_replace('/\D/', '', (string) ($data['representative_cpf'] ?? '')),
        ];
        $old = [
            'name' => trim((string) $setting->representative_name),
            'email' => mb_strtolower(trim((string) $setting->representative_email)),
            'cpf' => preg_replace('/\D/', '', (string) $setting->representative_cpf),
        ];

        $setting->sender_policy = $data['sender_policy'];
        $setting->representative_name = $new['name'] ?: null;
        $setting->representative_email = $new['email'] ?: null;
        $setting->representative_cpf = ($data['representative_cpf'] ?? null) ?: null;

        // Trocar o representante apaga o aceite: o novo precisa ser autorizado de novo.
        if ($new !== $old) {
            $setting->delegation_accepted_at = null;
            $setting->delegation_accepted_by = null;
            $setting->delegation_accepted_ip = null;
        }

        if ($request->boolean('accept_delegation') && $setting->delegation_accepted_at === null && $new['name'] !== '' && $new['email'] !== '') {
            $setting->delegation_accepted_at = now();
            $setting->delegation_accepted_by = $user->getKey();
            $setting->delegation_accepted_ip = $request->ip();
        }

        $setting->save();

        return redirect()->route('company.signing.edit')->with('status', 'Configuração salva.');
    }
}
