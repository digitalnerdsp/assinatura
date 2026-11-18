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
