<?php

declare(strict_types=1);

use App\Mail\PlatformInviteMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function pfUser(string $email, ?string $platformRole = null, string $role = 'owner'): User
{
    $u = User::create(['tenant_id' => Tenant::create(['name' => 'Empresa '.$email])->id, 'name' => 'Pessoa '.$email, 'email' => $email, 'password' => 'senha-muito-forte', 'role' => $role]);
    if ($platformRole !== null) {
        $u->forceFill(['platform_role' => $platformRole])->save();
    }

    return $u->fresh();
}

beforeEach(function () {
    config(['platform.super_admins' => ['raiz@plataforma.com']]);
    Mail::fake();
});

it('quem é raiz, super admin ou admin entra na plataforma; usuário comum recebe 404', function () {
    $raiz = pfUser('raiz@plataforma.com');
    $super = pfUser('super@plataforma.com', 'super_admin');
    $admin = pfUser('admin@plataforma.com', 'admin');
    $comum = pfUser('comum@cliente.com');

    expect($raiz->isRootAdmin())->toBeTrue()->and($raiz->isSuperAdmin())->toBeTrue()->and($raiz->isPlatformAdmin())->toBeTrue()
        ->and($super->isRootAdmin())->toBeFalse()->and($super->isSuperAdmin())->toBeTrue()
        ->and($admin->isSuperAdmin())->toBeFalse()->and($admin->isPlatformAdmin())->toBeTrue()
        ->and($comum->isPlatformAdmin())->toBeFalse();

    foreach ([$raiz, $super, $admin] as $u) {
        $this->actingAs($u)->get('/admin')->assertOk();
    }
    $this->actingAs($comum)->get('/admin')->assertNotFound();
    $this->get('/admin/usuarios')->assertNotFound();
});

it('admin comum opera clientes, saúde e selagem, mas recebe 403 em usuários, e-mail e configurações', function () {
    $this->actingAs(pfUser('admin@plataforma.com', 'admin'));

    foreach (['/admin/clientes', '/admin/saude', '/admin/selagem'] as $url) {
        $this->get($url)->assertOk();
    }
    foreach (['/admin/usuarios', '/admin/email', '/admin/configuracoes'] as $url) {
        $this->get($url)->assertForbidden();
    }
    $this->post('/admin/usuarios/promover', ['email' => 'comum@cliente.com', 'role' => 'admin'])->assertForbidden();
    $this->post('/admin/usuarios/convidar', ['name' => 'N', 'email' => 'n@x.com', 'role' => 'admin'])->assertForbidden();
    $this->put('/admin/email', [])->assertForbidden();
});

it('o menu mostra Usuários da plataforma só para super admin', function () {
    $this->actingAs(pfUser('admin@plataforma.com', 'admin'))->get('/admin')->assertOk()->assertDontSee('Usuários da plataforma');
    $this->actingAs(pfUser('super@plataforma.com', 'super_admin'))->get('/admin')->assertOk()->assertSee('Usuários da plataforma');
});

it('super admin promove uma conta existente e depois muda o papel', function () {
    $raiz = pfUser('raiz@plataforma.com');
    $alvo = pfUser('alvo@cliente.com');
    $this->actingAs($raiz);

    $this->post('/admin/usuarios/promover', ['email' => 'ALVO@cliente.com', 'role' => 'admin'])->assertRedirect()->assertSessionHasNoErrors();
    $alvo = $alvo->fresh();
    expect($alvo->platform_role)->toBe('admin')->and($alvo->platform_granted_by)->toBe($raiz->id)->and($alvo->platform_granted_at)->not->toBeNull();

    $this->put('/admin/usuarios/'.$alvo->id, ['role' => 'super_admin'])->assertRedirect();
    expect($alvo->fresh()->platform_role)->toBe('super_admin')->and($alvo->fresh()->isSuperAdmin())->toBeTrue();

    $this->post('/admin/usuarios/promover', ['email' => 'ninguem@x.com', 'role' => 'admin'])->assertSessionHasErrors('email');
    $this->post('/admin/usuarios/promover', ['email' => 'alvo@cliente.com', 'role' => 'dono'])->assertSessionHasErrors('role');
});

it('revogar tira o acesso na próxima requisição e não apaga a conta', function () {
    $this->actingAs(pfUser('raiz@plataforma.com'));
    $admin = pfUser('admin@plataforma.com', 'admin');

    $this->delete('/admin/usuarios/'.$admin->id)->assertRedirect();
    $admin = $admin->fresh();
    expect($admin)->not->toBeNull()->and($admin->platform_role)->toBeNull()->and($admin->isPlatformAdmin())->toBeFalse();

    $this->actingAs($admin)->get('/admin')->assertNotFound();
});

it('ninguém altera um raiz nem a si mesmo, e o admin não promove ninguém', function () {
    $raiz = pfUser('raiz@plataforma.com');
    $super = pfUser('super@plataforma.com', 'super_admin');
    $this->actingAs($super);

    $this->delete('/admin/usuarios/'.$raiz->id)->assertSessionHasErrors();
    $this->put('/admin/usuarios/'.$raiz->id, ['role' => 'admin'])->assertSessionHasErrors();
    $this->delete('/admin/usuarios/'.$super->id)->assertSessionHasErrors();
    $this->put('/admin/usuarios/'.$super->id, ['role' => 'admin'])->assertSessionHasErrors();
    expect($raiz->fresh()->isSuperAdmin())->toBeTrue()->and($super->fresh()->platform_role)->toBe('super_admin');

    // um usuário sem papel de plataforma não pode ser alterado por esta rota (não é da equipe)
    $comum = pfUser('comum@cliente.com');
    $this->delete('/admin/usuarios/'.$comum->id)->assertSessionHasErrors();
});

it('convida um novo admin por e-mail: ele define a senha, entra e já é da plataforma', function () {
    $this->actingAs(pfUser('raiz@plataforma.com'));

    $this->post('/admin/usuarios/convidar', ['name' => 'Nova Pessoa', 'email' => 'nova@plataforma.com', 'role' => 'admin'])->assertRedirect()->assertSessionHasNoErrors();

    $token = null;
    Mail::assertSent(PlatformInviteMail::class, function (PlatformInviteMail $m) use (&$token) {
        $token = basename($m->url);

        return $m->hasTo('nova@plataforma.com');
    });
    $novo = User::where('email', 'nova@plataforma.com')->firstOrFail();
    expect($novo->platform_role)->toBe('admin')->and($novo->isPendingInvite())->toBeTrue()->and($novo->isPlatformAdmin())->toBeFalse(); // só vale depois de aceitar

    auth()->logout();
    $this->post('/convite/'.$token, ['password' => 'senha-bem-forte-1', 'password_confirmation' => 'senha-bem-forte-1'])->assertRedirect('/painel');
    $this->get('/admin')->assertOk();

    // e-mail já usado é recusado
    $this->actingAs(pfUser('raiz2@x.com', 'super_admin'));
    $this->post('/admin/usuarios/convidar', ['name' => 'Outra', 'email' => 'nova@plataforma.com', 'role' => 'admin'])->assertSessionHasErrors('email');
});

it('conta desativada ou empresa suspensa perde o painel da plataforma', function () {
    $admin = pfUser('admin@plataforma.com', 'admin');
    $admin->forceFill(['deactivated_at' => now()])->save();
    $this->actingAs($admin->fresh())->get('/admin')->assertRedirect('/entrar');
    expect($admin->fresh()->isPlatformAdmin())->toBeFalse();
});

it('a lista mostra raiz como fixo, quem concedeu e o papel de cada um', function () {
    $raiz = pfUser('raiz@plataforma.com');
    $admin = pfUser('admin@plataforma.com', 'admin');
    $admin->forceFill(['platform_granted_by' => $raiz->id, 'platform_granted_at' => now()])->save();
    pfUser('comum@cliente.com');

    $this->actingAs($raiz)->get('/admin/usuarios')->assertOk()
        ->assertSee('raiz@plataforma.com')->assertSee('Fixo')
        ->assertSee('admin@plataforma.com')->assertSee('Administrador')->assertSee('Pessoa raiz@plataforma.com')
        ->assertDontSee('comum@cliente.com');
});
