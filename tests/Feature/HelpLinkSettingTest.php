<?php

declare(strict_types=1);

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function helpRegister(string $email): void
{
    test()->post('/cadastro', [
        'company' => 'Empresa '.$email, 'name' => 'Pessoa', 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');
}

it('o super admin salva o link da Central de ajuda e ele aparece no menu de todos', function () {
    config(['platform.super_admins' => ['admin@plataforma.com']]);
    helpRegister('admin@plataforma.com');

    $this->put('/admin/configuracoes', ['registration_open' => 1, 'help_url' => 'https://ajuda.exemplo.com'])->assertRedirect();
    expect(Setting::helpUrl())->toBe('https://ajuda.exemplo.com');

    auth()->logout();
    helpRegister('comum@empresa.com');
    $this->get('/painel')
        ->assertSee('href="https://ajuda.exemplo.com"', false)
        ->assertSee('target="_blank"', false)
        ->assertSee('rel="noopener"', false);
});

it('recusa esquemas perigosos e links malformados', function (string $bad) {
    config(['platform.super_admins' => ['admin@plataforma.com']]);
    helpRegister('admin@plataforma.com');

    $this->from('/admin/configuracoes')->put('/admin/configuracoes', ['help_url' => $bad])->assertSessionHasErrors('help_url');

    expect(Setting::helpUrl())->toBeNull();
})->with(['javascript:alert(1)', 'data:text/html,x', 'ftp://x.com', 'não é link']);

it('link vazio remove o item do menu', function () {
    config(['platform.super_admins' => ['admin@plataforma.com']]);
    helpRegister('admin@plataforma.com');
    Setting::put('help_url', 'https://ajuda.exemplo.com');

    $this->put('/admin/configuracoes', ['help_url' => ''])->assertRedirect();

    expect(Setting::helpUrl())->toBeNull();
    $this->get('/painel')->assertDontSee('Central de ajuda');
});

it('usuário comum não consegue alterar', function () {
    helpRegister('comum@empresa.com');

    $this->put('/admin/configuracoes', ['help_url' => 'https://x.com'])->assertNotFound();

    expect(Setting::helpUrl())->toBeNull();
});

it('salvar só o formulário de Cadastros não apaga o link da Central de ajuda', function () {
    config(['platform.super_admins' => ['admin@plataforma.com']]);
    helpRegister('admin@plataforma.com');
    Setting::put('help_url', 'https://ajuda.exemplo.com');

    $this->put('/admin/configuracoes', ['registration_open' => 1])->assertRedirect();

    expect(Setting::helpUrl())->toBe('https://ajuda.exemplo.com');
});
