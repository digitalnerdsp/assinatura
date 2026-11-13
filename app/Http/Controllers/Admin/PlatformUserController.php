<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Mail\PlatformInviteMail;
use App\Models\User;
use App\Platform\PlatformUserService;
use App\Team\TeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/** Usuários da plataforma (admins e super admins). Rota só para super admin. */
final class PlatformUserController extends Controller
{
    public function __construct(private readonly PlatformUserService $team)
    {
    }

    public function index(Request $request): View
    {
        $roots = (array) config('platform.super_admins', []);
        $members = User::query()->with('grantedBy:id,name')
            ->where(fn ($q) => $q->whereNotNull('platform_role')->orWhereIn(DB::raw('lower(email)'), $roots ?: ['-']))
            ->orderBy('name')->get();

        return view('admin.users.index', ['members' => $members, 'me' => $request->user()]);
    }

    public function promote(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190'], 'role' => ['required', 'in:admin,super_admin']]);
        $target = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->first();
        if ($target === null) {
            return back()->withErrors(['email' => 'Nenhuma conta com este e-mail. Use "Convidar" para criar uma.'])->withInput();
        }

        return $this->run(fn () => $this->team->grant($request->user(), $target, $data['role']), $target->name.' agora é '.$this->label($data['role']).'.');
    }

    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', 'in:admin,super_admin'],
        ]);

        try {
            [$member, $token] = $this->team->invite($request->user(), $data['name'], $data['email'], $data['role']);
        } catch (FlowException $e) {
            return back()->withErrors([$e->reason === 'email_taken' ? 'email' : 'team' => $e->getMessage()])->withInput();
        }

        return $this->deliver($request->user(), $member, $token, 'Convite enviado para '.$member->email.'.');
    }

    public function resend(Request $request, User $user): RedirectResponse
    {
        try {
            $token = $this->team->resend($request->user(), $user);
        } catch (FlowException $e) {
            return back()->withErrors(['team' => $e->getMessage()]);
        }

        return $this->deliver($request->user(), $user, $token, 'Convite reenviado para '.$user->email.'. O link anterior deixou de valer.');
    }

    /** Envia o e-mail e sempre devolve o link para o admin poder copiar (o e-mail pode cair no spam ou ser barrado). */
    private function deliver(User $actor, User $member, string $token, string $ok): RedirectResponse
    {
        $url = route('invite.show', $token);
        try {
            Mail::to($member->email, $member->name)->send(new PlatformInviteMail($member, $actor, $url));
        } catch (\Throwable $e) {
            Log::warning('Falha ao enviar convite da plataforma', ['user' => $member->getKey(), 'error' => $e->getMessage()]);

            return redirect()->route('admin.users.index')->with('warning', 'O e-mail não saiu: '.mb_substr($e->getMessage(), 0, 200).' Copie o link abaixo e envie por outro meio, ou confira o e-mail (SMTP) da plataforma.')->with('invite_link', $url);
        }
        Log::info('Convite da plataforma enviado ao servidor de e-mail', ['user' => $member->getKey()]);

        return redirect()->route('admin.users.index')->with('status', $ok.' Se não chegar em alguns minutos, confira o spam ou use o link abaixo.')->with('invite_link', $url);
    }

    public function role(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', 'in:admin,super_admin']]);

        return $this->run(fn () => $this->team->change($request->user(), $user, $data['role']), $user->name.' agora é '.$this->label($data['role']).'.');
    }

    public function revoke(Request $request, User $user): RedirectResponse
    {
        return $this->run(fn () => $this->team->revoke($request->user(), $user), 'O acesso de '.$user->name.' à plataforma foi retirado. A conta dela continua normal.');
    }

    private function run(\Closure $fn, string $ok): RedirectResponse
    {
        try {
            $fn();
        } catch (FlowException $e) {
            return back()->withErrors(['team' => $e->getMessage()]);
        }

        return redirect()->route('admin.users.index')->with('status', $ok);
    }

    private function label(string $role): string
    {
        return $role === 'super_admin' ? 'super administrador' : 'administrador';
    }
}
