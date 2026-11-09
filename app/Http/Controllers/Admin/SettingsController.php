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
            'admins' => config('platform.super_admins'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Setting::put('registration_open', $request->boolean('registration_open') ? '1' : '0');

        return back()->with('status', 'Configurações salvas.');
    }
}
