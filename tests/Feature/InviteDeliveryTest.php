<?php

declare(strict_types=1);

use App\Mail\PlatformInviteMail;
use App\Mail\TeamInviteMail;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\PlatformUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.super_admins' => ['raiz@plataforma.com']]);
    Mail::fake();
});

function idUser(string $email, ?string $platformRole = null, string $role = 'owner'): User
{
    $u = User::create(['tenant_id' => Tenant::create(['name' => 'Empresa '.$email])->id, 'name' => 'Pessoa '.$email, 'email' => $email, 'password' => 'senha-muito-forte', 'role' => $role]);
    if ($platformRole) {
        $u->forceFill(['platform_role' => $platformRole])->save();
    }

    return $u->fresh();
}

it('os e-mails de convite têm versão em texto puro e HTML completo, com o link também escrito por extenso', function () {
    $actor = idUser('raiz@plataforma.com');
    $member = idUser('novo@x.com', 'admin');

    $p = new PlatformInviteMail($member, $actor, 'https://x.test/convite/ABC');
    expect($p->content()->text)->not->toBeNull()->and($p->content()->view)->not->toBeNull();
    expect($p->render())->toContain('<html')->toContain('https://x.test/convite/ABC');
    expect(view($p->content()->text, $p->buildViewData())->render())->toContain('https://x.test/convite/ABC')->not->toContain('<p>');

    $t = new TeamInviteMail($member, $actor, 'https://x.test/convite/DEF');
    expect($t->content()->text)->not->toBeNull()->and($t->render())->toContain('<html')->toContain('https://x.test/convite/DEF');
});

it('o super admin reenvia o convite da plataforma: link novo, o antigo para de valer, e o link aparece para copiar', function () {
    $this->actingAs(idUser('raiz@plataforma.com'));
    $this->post('/admin/usuarios/convidar', ['name' => 'Brau', 'email' => 'brau@x.com', 'role' => 'super_admin'])->assertRedirect()->assertSessionHas('invite_link');

    $old = null;
    Mail::assertSent(PlatformInviteMail::class, function ($m) use (&$old) {
        $old = basename($m->url);

        return true;
    });
    $brau = User::where('email', 'brau@x.com')->firstOrFail();

    Mail::fake();
    $res = $this->post('/admin/usuarios/'.$brau->id.'/reenviar')->assertRedirect()->assertSessionHas('invite_link');
    $new = null;
    Mail::assertSent(PlatformInviteMail::class, function ($m) use (&$new) {
        $new = basename($m->url);

        return $m->hasTo('brau@x.com');
    });
    expect($new)->not->toBe($old)->and(session('invite_link'))->toContain($new);

    auth()->logout();
    $this->get('/convite/'.$old)->assertNotFound();
    $this->get('/convite/'.$new)->assertOk();
});

it('o link do convite fica visível na tela da equipe com botão de copiar', function () {
    $this->actingAs(idUser('raiz@plataforma.com'));
    $res = $this->followingRedirects()->post('/admin/usuarios/convidar', ['name' => 'Brau', 'email' => 'brau@x.com', 'role' => 'admin']);
    $res->assertOk()->assertSee('/convite/', false)->assertSee('Copiar link');
});

it('reenviar só vale para convite pendente, de quem é da equipe, por super admin; raiz e si mesmo não', function () {
    $raiz = idUser('raiz@plataforma.com');
    $this->actingAs($raiz);
    [$pending] = app(PlatformUserService::class)->invite($raiz, 'P', 'p@x.com', 'admin');
    $aceito = idUser('aceito@x.com', 'admin');
    $comum = idUser('comum@x.com');

    $this->post('/admin/usuarios/'.$aceito->id.'/reenviar')->assertSessionHasErrors('team');   // já aceitou
    $this->post('/admin/usuarios/'.$comum->id.'/reenviar')->assertSessionHasErrors('team');    // não é da equipe
    $this->post('/admin/usuarios/'.$raiz->id.'/reenviar')->assertSessionHasErrors('team');     // si mesmo
    Mail::assertNothingSent();

    $this->actingAs(idUser('admin@plataforma.com', 'admin'));
    $this->post('/admin/usuarios/'.$pending->id.'/reenviar')->assertForbidden();
    Mail::assertNothingSent();
});

it('o convite de empresa também mostra o link para copiar', function () {
    $this->post('/cadastro', ['company' => 'ACME', 'name' => 'Dono', 'email' => 'dono@acme.com', 'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte'])->assertRedirect('/painel');
    $this->followingRedirects()->post('/configuracoes/usuarios', ['name' => 'Novo', 'email' => 'novo@acme.com'])->assertOk()->assertSee('/convite/', false)->assertSee('Copiar link');
});
