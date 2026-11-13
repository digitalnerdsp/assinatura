<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Mail\TeamInviteMail;
use App\Models\User;
use App\Team\TeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/** Configurações › Usuários: todos veem a lista; só o dono convida, desativa, reativa e remove. */
final class TeamController extends Controller
{
    public function __construct(private readonly TeamService $team)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('team.index', [
            'members' => User::query()->where('tenant_id', $user->tenant_id)->orderByRaw("role = 'owner' desc")->orderBy('name')->get(),
            'isOwner' => $user->role === 'owner',
            'me' => $user,
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
        ]);

        try {
            [$member, $token] = $this->team->invite($owner, $data['name'], $data['email']);
        } catch (FlowException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput();
        }

        return $this->sendInvite($owner, $member, $token, 'Convite enviado para '.$member->email.'.');
    }

    public function resend(Request $request, User $user): RedirectResponse
    {
        $owner = $this->owner($request);
        $member = $this->member($owner, $user);

        try {
            $token = $this->team->resend($owner, $member);
        } catch (FlowException $e) {
            return back()->withErrors(['team' => $e->getMessage()]);
        }

        return $this->sendInvite($owner, $member, $token, 'Convite reenviado para '.$member->email.'. O link anterior deixou de valer.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        return $this->act($request, $user, fn ($o, $m) => $this->team->deactivate($o, $m), 'Acesso de :name desativado.');
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        return $this->act($request, $user, fn ($o, $m) => $this->team->reactivate($o, $m), 'Acesso de :name reativado.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        return $this->act($request, $user, fn ($o, $m) => $this->team->remove($o, $m), ':name foi removido. Os documentos criados por ele continuam na empresa.');
    }

    private function act(Request $request, User $user, \Closure $fn, string $message): RedirectResponse
    {
        $owner = $this->owner($request);
        $member = $this->member($owner, $user);

        try {
            $fn($owner, $member);
        } catch (FlowException $e) {
            return back()->withErrors(['team' => $e->getMessage()]);
        }

        return redirect()->route('team.index')->with('status', str_replace(':name', $member->name, $message));
    }

    private function sendInvite(User $owner, User $member, string $token, string $ok): RedirectResponse
    {
        $url = route('invite.show', $token);
        try {
            Mail::to($member->email, $member->name)->send(new TeamInviteMail($member, $owner, $url));
        } catch (\Throwable $e) {
            Log::warning('Falha ao enviar convite de usuário', ['user' => $member->getKey(), 'error' => $e->getMessage()]);

            return redirect()->route('team.index')->with('warning', 'O usuário foi criado, mas o e-mail não saiu. Confira as configurações de e-mail e use "Reenviar convite".');
        }

        return redirect()->route('team.index')->with('status', $ok);
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->role === 'owner', 403);

        return $user;
    }

    private function member(User $owner, User $user): User
    {
        abort_unless($user->tenant_id === $owner->tenant_id, 404);

        return $user;
    }
}
