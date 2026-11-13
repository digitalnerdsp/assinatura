<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('admin.settings', [
            'registrationOpen' => Setting::registrationOpen(),
            'helpUrl' => Setting::helpUrl(),
            'admins' => config('platform.super_admins'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['help_url' => ['nullable', 'url:http,https', 'max:300']], [
            'help_url.url' => 'Informe um endereço completo que comece com http:// ou https://.',
        ]);

        Setting::put('registration_open', $request->boolean('registration_open') ? '1' : '0');
        if ($request->has('help_url')) { // o formulário de Cadastros não envia o campo: não apagar o link salvo
            Setting::put('help_url', $data['help_url'] ?? null);
        }

        return back()->with('status', 'Configurações salvas.');
    }
}
