<?php

declare(strict_types=1);

use App\Envelopes\Exceptions\FlowException;
use App\Mail\TeamInviteMail;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Tenant;
use App\Models\User;
use App\Team\TeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function teamOwner(): User
{
    test()->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => 'dono@acme.com',
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', 'dono@acme.com')->firstOrFail();
}

function teamMember(User $owner, string $email = 'colega@acme.com'): User
{
    return User::create(['tenant_id' => $owner->tenant_id, 'name' => 'Colega', 'email' => $email, 'password' => 'senha-muito-forte', 'role' => 'member']);
}

/** Convida pela tela e devolve o token do link enviado por e-mail. */
function inviteByScreen(string $email = 'novo@acme.com'): string
{
    $token = null;
    test()->post('/configuracoes/usuarios', ['name' => 'Novo', 'email' => $email])->assertRedirect()->assertSessionHasNoErrors();
    Mail::assertSent(TeamInviteMail::class, function (TeamInviteMail $m) use ($email, &$token) {
        $token = basename($m->url);

        return $m->hasTo($email);
    });

    return $token;
}

beforeEach(fn () => Mail::fake());

it('o dono convida, o convidado define a senha e entra na mesma empresa como membro', function () {
    $owner = teamOwner();
    $token = inviteByScreen();

    $this->get('/configuracoes/usuarios')->assertOk()->assertSee('novo@acme.com')->assertSee('Convite pendente');

    auth()->logout();
    $this->get('/convite/'.$token)->assertOk()->assertSee('novo@acme.com');
    $this->post('/convite/'.$token, ['password' => 'outra-senha-forte', 'password_confirmation' => 'outra-senha-forte'])->assertRedirect('/painel');

    $novo = User::where('email', 'novo@acme.com')->firstOrFail();
    expect($novo->tenant_id)->toBe($owner->tenant_id)->and($novo->role)->toBe('member')
        ->and($novo->isPendingInvite())->toBeFalse()->and($novo->invited_by)->toBe($owner->id);
    $this->assertAuthenticatedAs($novo);

    // o link não vale de novo
    auth()->logout();
    $this->get('/convite/'.$token)->assertNotFound();
    $this->post('/convite/'.$token, ['password' => 'mais-uma-senha', 'password_confirmation' => 'mais-uma-senha'])->assertNotFound();
});

it('recusa token errado ou expirado e e-mail já cadastrado em qualquer empresa', function () {
    teamOwner();
    User::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'X', 'email' => 'x@outra.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);

    $this->post('/configuracoes/usuarios', ['name' => 'X', 'email' => 'X@Outra.com'])->assertSessionHasErrors('email');
    Mail::assertNothingSent();

    $token = inviteByScreen();
    auth()->logout();
    $this->get('/convite/'.Str::random(40))->assertNotFound();

    $this->travel(8)->days();
    $this->get('/convite/'.$token)->assertNotFound();
    expect(fn () => app(TeamService::class)->accept($token, 'senha-muito-forte'))->toThrow(FlowException::class);
});

it('reenviar gera um link novo e o anterior deixa de valer', function () {
    teamOwner();
    $old = inviteByScreen();
    $novo = User::where('email', 'novo@acme.com')->firstOrFail();

    Mail::fake();
    $this->post('/configuracoes/usuarios/'.$novo->id.'/reenviar')->assertRedirect();
    $new = null;
    Mail::assertSent(TeamInviteMail::class, function (TeamInviteMail $m) use (&$new) {
        $new = basename($m->url);

        return true;
    });

    auth()->logout();
    expect($new)->not->toBe($old);
    $this->get('/convite/'.$old)->assertNotFound();
    $this->get('/convite/'.$new)->assertOk();
});

it('desativar impede o login e derruba a sessão aberta; reativar devolve o acesso', function () {
    $owner = teamOwner();
    $member = teamMember($owner);

    $this->post('/configuracoes/usuarios/'.$member->id.'/desativar')->assertRedirect();
    expect($member->fresh()->isActive())->toBeFalse();

    $this->actingAs($member->fresh())->get('/painel')->assertRedirect('/entrar');
    $this->assertGuest();

    $this->post('/entrar', ['email' => 'colega@acme.com', 'password' => 'senha-muito-forte'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->actingAs($owner->fresh())->post('/configuracoes/usuarios/'.$member->id.'/reativar')->assertRedirect();
    auth()->logout();
    $this->post('/entrar', ['email' => 'colega@acme.com', 'password' => 'senha-muito-forte'])->assertRedirect('/painel');
    $this->assertAuthenticatedAs($member->fresh());
});

it('remover apaga o usuário e mantém os documentos que ele criou', function () {
    $owner = teamOwner();
    $member = teamMember($owner);
    $doc = Document::create(['tenant_id' => $owner->tenant_id, 'name' => 'a.pdf', 'storage_path' => 'documents/a.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);
    $env = Envelope::create(['tenant_id' => $owner->tenant_id, 'document_id' => $doc->id, 'title' => 'Dele', 'status' => 'draft', 'verification_code' => 'ABCDEFGHJKLM', 'created_by' => $member->id]);

    $this->delete('/configuracoes/usuarios/'.$member->id)->assertRedirect();

    expect(User::find($member->id))->toBeNull()->and($env->fresh()->created_by)->toBeNull();
});

it('o dono não muda a si nem outro dono; membro não tem as ações; outra empresa é 404', function () {
    $owner = teamOwner();
    $otherOwner = User::create(['tenant_id' => $owner->tenant_id, 'name' => 'Sócio', 'email' => 'socio@acme.com', 'password' => 'senha-muito-forte', 'role' => 'owner']);
    $member = teamMember($owner);
    $stranger = User::create(['tenant_id' => Tenant::create(['name' => 'Outra'])->id, 'name' => 'Z', 'email' => 'z@outra.com', 'password' => 'senha-muito-forte', 'role' => 'member']);

    $this->post('/configuracoes/usuarios/'.$owner->id.'/desativar')->assertSessionHasErrors();
    $this->delete('/configuracoes/usuarios/'.$otherOwner->id)->assertSessionHasErrors();
    $this->post('/configuracoes/usuarios/'.$stranger->id.'/desativar')->assertNotFound();
    expect($owner->fresh()->isActive())->toBeTrue()->and(User::find($otherOwner->id))->not->toBeNull()
        ->and($stranger->fresh()->isActive())->toBeTrue();

    $service = app(TeamService::class);
    expect(fn () => $service->deactivate($owner, $owner))->toThrow(FlowException::class)
        ->and(fn () => $service->remove($owner, $stranger))->toThrow(FlowException::class);

    $this->actingAs($member)->get('/configuracoes/usuarios')->assertOk()->assertSee('Sócio')->assertDontSee('Convidar usuário');
    $this->post('/configuracoes/usuarios', ['name' => 'N', 'email' => 'n@acme.com'])->assertForbidden();
    $this->post('/configuracoes/usuarios/'.$otherOwner->id.'/desativar')->assertForbidden();
    $this->delete('/configuracoes/usuarios/'.$otherOwner->id)->assertForbidden();
});
