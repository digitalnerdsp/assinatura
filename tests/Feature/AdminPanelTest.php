<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function userFor(Tenant $tenant, string $email): User
{
    return User::create(['tenant_id' => $tenant->id, 'name' => 'U', 'email' => $email, 'password' => 'senha-muito-forte', 'role' => 'owner']);
}

beforeEach(function () {
    config(['platform.super_admins' => ['admin@plataforma.com']]);
});

it('o painel admin só abre para super admins (404 para os demais)', function () {
    $tenant = Tenant::create(['name' => 'Cliente']);
    $this->get('/admin')->assertRedirect('/entrar');

    $this->actingAs(userFor($tenant, 'cliente@ex.com'))->get('/admin')->assertNotFound();
    $this->get('/admin/clientes')->assertNotFound();
    $this->post("/admin/clientes/{$tenant->id}/suspender")->assertNotFound();
    expect($tenant->fresh()->isSuspended())->toBeFalse();
});

it('super admin vê métricas, lista e detalha clientes', function () {
    $tenant = Tenant::create(['name' => 'Empresa Alfa']);
    userFor($tenant, 'alfa@ex.com');
    $admin = userFor(Tenant::create(['name' => 'Plataforma']), 'admin@plataforma.com');

    $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Administração da plataforma');
    $this->get('/admin/clientes')->assertOk()->assertSee('Empresa Alfa');
    $this->get('/admin/clientes?q=alfa')->assertOk()->assertSee('Empresa Alfa')->assertDontSee('Plataforma');
    $this->get("/admin/clientes/{$tenant->id}")->assertOk()->assertSee('alfa@ex.com');
    $this->get('/admin/configuracoes')->assertOk();
});

it('suspender um cliente bloqueia usuários, API e novos envios', function () {
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'Cliente']);
    $user = userFor($tenant, 'cliente@ex.com');
    [, $plain] = App\Models\ApiKey::issue($tenant, 'k');
    $admin = userFor(Tenant::create(['name' => 'Plataforma']), 'admin@plataforma.com');

    $envelope = app(EnvelopeService::class)->create($tenant, null, "%PDF-1.4\n%%EOF\n", 'a.pdf', 'T', [['name' => 'A', 'email' => 'a@a.com']]);

    $this->actingAs($admin)->post("/admin/clientes/{$tenant->id}/suspender")->assertRedirect();
    expect($tenant->fresh()->isSuspended())->toBeTrue();

    // usuário do cliente é desconectado
    auth()->logout();
    $this->actingAs($user)->get('/painel')->assertRedirect('/entrar');

    // API responde 403
    $this->getJson('/api/v1/envelopes/x', ['Authorization' => "Bearer {$plain}"])->assertForbidden();

    // novo envio bloqueado
    expect(fn () => app(EnvelopeService::class)->send($envelope))->toThrow(FlowException::class, 'suspensa');

    // reativar libera
    $this->actingAs($admin)->post("/admin/clientes/{$tenant->id}/reativar")->assertRedirect();
    expect($tenant->fresh()->isSuspended())->toBeFalse();
    app(EnvelopeService::class)->send($envelope->fresh());
});

it('aplica o limite mensal de envelopes do plano', function () {
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'Cliente', 'monthly_envelope_limit' => 1]);
    $make = fn () => app(EnvelopeService::class)->create($tenant, null, "%PDF-1.4\n%%EOF\n", 'a.pdf', 'T', [['name' => 'A', 'email' => 'a@a.com']]);

    app(EnvelopeService::class)->send($make());

    expect(fn () => app(EnvelopeService::class)->send($make()))->toThrow(FlowException::class, 'Limite mensal');
});

it('admin altera plano e limite do cliente', function () {
    $tenant = Tenant::create(['name' => 'Cliente']);
    $admin = userFor(Tenant::create(['name' => 'Plataforma']), 'admin@plataforma.com');

    $this->actingAs($admin)->put("/admin/clientes/{$tenant->id}", ['plan' => 'pro', 'monthly_envelope_limit' => 500, 'admin_notes' => 'VIP'])
        ->assertRedirect();

    $tenant->refresh();
    expect($tenant->plan)->toBe('pro')->and($tenant->monthly_envelope_limit)->toBe(500)->and($tenant->admin_notes)->toBe('VIP');

    $this->put("/admin/clientes/{$tenant->id}", ['plan' => 'invalido'])->assertSessionHasErrors('plan');
});

it('fechar os cadastros bloqueia /cadastro mas mantém o login', function () {
    $admin = userFor(Tenant::create(['name' => 'Plataforma']), 'admin@plataforma.com');

    $this->actingAs($admin)->put('/admin/configuracoes', [])->assertRedirect();
    expect(Setting::registrationOpen())->toBeFalse();
    auth()->logout();

    $this->get('/cadastro')->assertOk()->assertSee('cadastros estão fechados');
    $this->post('/cadastro', [
        'company' => 'X', 'name' => 'X', 'email' => 'x@x.com', 'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertForbidden();
    expect(User::where('email', 'x@x.com')->exists())->toBeFalse();

    $this->get('/entrar')->assertOk();
});
