<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AuthController extends Controller
{
    public function showRegister(): View
    {
        return view('auth.register', ['open' => Setting::registrationOpen()]);
    }

    public function register(Request $request): RedirectResponse
    {
        abort_unless(Setting::registrationOpen(), 403, 'Os cadastros estão fechados no momento.');
        if (is_string($request->input('email'))) {
            app(\App\Team\TeamService::class)->purgeExpiredInvite($request->input('email'));
        }

        $data = $request->validate([
            'company' => ['required', 'string', 'max:150'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $tenant = Tenant::query()->create(['name' => $data['company']]);
            app(\App\Plans\PlanService::class)->assign($tenant, app(\App\Plans\PlanService::class)->defaultPlan());

            return User::query()->create([
                'tenant_id' => $tenant->getKey(),
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
                'role' => 'owner',
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['email' => mb_strtolower($credentials['email']), 'password' => $credentials['password']], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'E-mail ou senha incorretos.'])->onlyInput('email');
        }

        if (! Auth::user()->isActive() || Auth::user()->isPendingInvite()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => 'Seu acesso foi desativado. Fale com o responsável da empresa.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
