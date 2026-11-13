<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Envelopes\Exceptions\FlowException;
use App\Http\Controllers\Controller;
use App\Team\TeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Página pública do convite: o convidado cria a senha e entra. */
final class InviteController extends Controller
{
    public function __construct(private readonly TeamService $team)
    {
    }

    public function show(string $token): View
    {
        $user = $this->team->findInvite($token);
        abort_if($user === null, 404, 'Este convite não vale mais. Peça um novo ao responsável da empresa.');

        return view('team.accept', ['invitee' => $user, 'token' => $token]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        abort_if($this->team->findInvite($token) === null, 404, 'Este convite não vale mais. Peça um novo ao responsável da empresa.');
        $data = $request->validate(['password' => ['required', 'string', 'min:10', 'max:200', 'confirmed']]);

        try {
            $user = $this->team->accept($token, $data['password']);
        } catch (FlowException) {
            abort(404, 'Este convite não vale mais. Peça um novo ao responsável da empresa.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
