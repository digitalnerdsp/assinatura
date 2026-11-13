<?php

declare(strict_types=1);

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function menuRegister(string $email = 'dono@acme.com'): void
{
    test()->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');
}

/** Só o HTML do menu lateral (entre a abertura do menu e o fechamento do aside). */
function sidebarHtml(string $html): string
{
    preg_match('/<ul class="metismenu".*?<\/ul>\s*<\/div>\s*<header>/s', $html, $m);

    return $m[0] ?? '';
}

it('mostra o menu na ordem do escopo', function () {
    menuRegister();

    $this->get('/painel')->assertOk()->assertSeeInOrder([
        'Painel', 'Documentos', 'Documentos criados', 'Minhas assinaturas', 'Lixeira',
        'Modelos', 'Configurações', 'Meu perfil', 'Pastas e etiquetas', 'API', 'Webhooks', 'Verificar documento',
    ]);
});

it('esconde a seção Plataforma de quem não é super admin e mostra ao super admin', function () {
    menuRegister('dono@acme.com');
    $this->get('/painel')->assertDontSee('Saúde do sistema');

    auth()->logout();
    config(['platform.super_admins' => ['admin@plataforma.com']]);
    menuRegister('admin@plataforma.com');
    $this->get('/painel')->assertSee('Saúde do sistema')->assertSee('Selagem do PDF');
});

it('o pai fica aberto e ativo na página de um filho', function () {
    menuRegister();

    $menu = sidebarHtml($this->get('/documentos/lixeira')->assertOk()->getContent());

    expect($menu)->not->toBe('')
        ->and($menu)->toMatch('/<li class="mm-active">\s*<a class="has-arrow"[^>]*>.*?Documentos<\/div><\/a>\s*<ul class="mm-collapse mm-show">/s');
});

it('tem o botão Criar documento no topo apontando para a criação', function () {
    menuRegister();

    $html = $this->get('/envelopes/novo')->assertOk()->getContent();
    preg_match('/<div class="top-menu.*?<\/ul>/s', $html, $m);

    expect($m[0] ?? '')->toContain('Criar documento')->toContain(route('envelopes.create'));
});

it('mostra a Central de ajuda só quando há link configurado', function () {
    menuRegister();
    $this->get('/painel')->assertDontSee('Central de ajuda');

    Setting::put('help_url', 'https://ajuda.exemplo.com');

    $this->get('/painel')->assertSee('Central de ajuda')
        ->assertSee('href="https://ajuda.exemplo.com"', false)
        ->assertSee('rel="noopener"', false);
});
