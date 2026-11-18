<?php

declare(strict_types=1);

use App\Models\User;
use App\Team\TeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pwRegister(string $password, string $email = 'novo@wz.test')
{
    return test()->post('/cadastro', ['company' => 'Acme', 'name' => 'Dono', 'email' => $email, 'password' => $password, 'password_confirmation' => $password]);
}

it('o cadastro aceita senha de 8 caracteres e recusa 7', function () {
    pwRegister('1234567')->assertSessionHasErrors('password');
    expect(User::where('email', 'novo@wz.test')->exists())->toBeFalse();

    pwRegister('12345678')->assertRedirect('/painel');
    expect(User::where('email', 'novo@wz.test')->exists())->toBeTrue();
});

it('o convite aceita senha de 8 caracteres e recusa 7', function () {
    $owner = wzOwner();
    [$member, $token] = app(TeamService::class)->invite($owner, 'Fulano', 'fulano@wz.test');
    auth()->logout();

    $this->post("/convite/{$token}", ['password' => '1234567', 'password_confirmation' => '1234567'])->assertSessionHasErrors('password');
    $this->post("/convite/{$token}", ['password' => '12345678', 'password_confirmation' => '12345678'])->assertRedirect('/painel');
});

it('os textos das telas dizem 8 caracteres', function () {
    $owner = wzOwner();
    [, $token] = app(TeamService::class)->invite($owner, 'Fulano', 'fulano2@wz.test');
    auth()->logout();

    $this->get('/cadastro')->assertSee('mín. 8 caracteres')->assertDontSee('10 caracteres');
    $this->get("/convite/{$token}")->assertSee('mínimo 8 caracteres')->assertDontSee('10 caracteres');
});

it('as correções de janelas e menus dos temas existem e centralizam a janela', function () {
    $css = file_get_contents(public_path('assets/css/legacy-fixes.css'));

    expect($css)->toContain('.legacy-scope .modal-dialog')->and($css)->toContain('.legacy-scope .dropdown .dropdown-menu')
        ->and($css)->not->toContain('margin: var(--bs-modal-margin); }'); // não pode zerar o margin automático lateral
});

it('o perfil tem o cartão para alterar a senha', function () {
    $u = wzOwner();

    $this->actingAs($u)->get('/perfil')->assertOk()->assertSee('Alterar senha')->assertSee('name="current_password"', false)->assertSee(route('profile.password'), false);
});

it('troca a senha com a senha atual correta e a nova passa a valer no login', function () {
    $u = wzOwner('troca@wz.test');
    $this->actingAs($u);
    $old = 'senha-muito-forte';

    $this->put('/perfil/senha', ['current_password' => $old, 'password' => 'nova-senha-1', 'password_confirmation' => 'nova-senha-1'])
        ->assertRedirect('/perfil')->assertSessionHas('status', 'Senha alterada.')->assertSessionHasNoErrors();

    expect(\Illuminate\Support\Facades\Hash::check('nova-senha-1', $u->fresh()->password))->toBeTrue();
    auth()->logout();
    $this->post('/entrar', ['email' => 'troca@wz.test', 'password' => $old])->assertSessionHasErrors();
    $this->post('/entrar', ['email' => 'troca@wz.test', 'password' => 'nova-senha-1'])->assertRedirect();
});

it('aceita a nova senha com 8 caracteres e recusa 7', function () {
    $u = wzOwner();
    $this->actingAs($u)->from('/perfil');

    $this->put('/perfil/senha', ['current_password' => 'senha-muito-forte', 'password' => '1234567', 'password_confirmation' => '1234567'])->assertSessionHasErrors('password');
    $this->put('/perfil/senha', ['current_password' => 'senha-muito-forte', 'password' => '12345678', 'password_confirmation' => '12345678'])->assertSessionHasNoErrors();
});

it('recusa senha atual errada, confirmação diferente e nova igual à atual, sem mudar nada', function () {
    $u = wzOwner();
    $hash = $u->password;
    $this->actingAs($u)->from('/perfil');

    $this->put('/perfil/senha', ['current_password' => 'errada-errada', 'password' => 'nova-senha-1', 'password_confirmation' => 'nova-senha-1'])->assertSessionHasErrors('current_password');
    $this->put('/perfil/senha', ['current_password' => 'senha-muito-forte', 'password' => 'nova-senha-1', 'password_confirmation' => 'outra-coisa'])->assertSessionHasErrors('password');
    $this->put('/perfil/senha', ['current_password' => 'senha-muito-forte', 'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte'])->assertSessionHasErrors('password');

    expect($u->fresh()->password)->toBe($hash);
});

it('quem não entrou na conta é mandado ao login e a troca tem limite de tentativas', function () {
    $this->put('/perfil/senha', ['current_password' => 'x', 'password' => 'y'])->assertRedirect('/entrar');

    $u = wzOwner();
    $this->actingAs($u);
    foreach (range(1, 5) as $i) {
        $this->put('/perfil/senha', ['current_password' => 'errada', 'password' => 'nova-senha-1', 'password_confirmation' => 'nova-senha-1']);
    }
    $this->put('/perfil/senha', ['current_password' => 'errada', 'password' => 'nova-senha-1', 'password_confirmation' => 'nova-senha-1'])->assertStatus(429);
});

it('encerra as outras sessões da conta e mantém a atual', function () {
    config(['session.driver' => 'database']);
    $u = wzOwner();
    \Illuminate\Support\Facades\DB::table('sessions')->insert([
        ['id' => 'outra-sessao-1', 'user_id' => $u->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
        ['id' => 'sessao-de-outro-usuario', 'user_id' => null, 'ip_address' => '2.2.2.2', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
    ]);

    app(\App\Http\Controllers\Web\ProfileController::class)->forgetOtherSessions($u->id, 'sessao-atual');

    expect(\Illuminate\Support\Facades\DB::table('sessions')->where('id', 'outra-sessao-1')->exists())->toBeFalse()
        ->and(\Illuminate\Support\Facades\DB::table('sessions')->where('id', 'sessao-de-outro-usuario')->exists())->toBeTrue();
});
