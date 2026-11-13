<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function mineRegister(string $email, string $company): User
{
    test()->post('/cadastro', [
        'company' => $company, 'name' => 'Dono '.$company, 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', $email)->firstOrFail();
}

function mineEnvelope(Tenant|string $tenant, string $title, string $status = 'sent', string $order = 'parallel'): Envelope
{
    $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
    $doc = Document::create(['tenant_id' => $tenantId, 'name' => 'a.pdf', 'storage_path' => 'documents/'.Str::uuid().'.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);

    return Envelope::create([
        'tenant_id' => $tenantId, 'document_id' => $doc->id, 'title' => $title, 'status' => $status,
        'signing_order' => $order, 'verification_code' => strtoupper(Str::random(12)),
    ]);
}

function mineSigner(Envelope $envelope, string $email, int $position = 1, string $status = 'pending'): Signer
{
    $plain = Str::random(40);

    return Signer::create([
        'envelope_id' => $envelope->id, 'name' => 'Colega', 'email' => $email, 'position' => $position, 'status' => $status,
        'token_hash' => hash('sha256', $plain), 'token_encrypted' => Crypt::encryptString($plain),
    ]);
}

it('lista os envelopes da minha empresa em que sou signatário, pelo meu e-mail', function () {
    $me = mineRegister('eu@acme.com', 'ACME');
    $envelope = mineEnvelope($me->tenant_id, 'Contrato interno');
    $signer = mineSigner($envelope, 'Eu@ACME.com');

    $this->get('/documentos/minhas-assinaturas')->assertOk()
        ->assertSee('Contrato interno')
        ->assertSee('Assinar')
        ->assertSee($signer->signUrl(), false);
});

it('não mostra envelopes de outra empresa mesmo com o mesmo e-mail', function () {
    $me = mineRegister('eu@acme.com', 'ACME');
    $other = Tenant::create(['name' => 'Outra']);
    $foreign = mineEnvelope($other, 'Documento sigiloso de terceiros');
    $foreignSigner = mineSigner($foreign, 'eu@acme.com');

    $this->get('/documentos/minhas-assinaturas')->assertOk()
        ->assertDontSee('Documento sigiloso de terceiros')
        ->assertDontSee($foreignSigner->signUrl(), false);
});

it('não mostra lixeira, arquivados nem rascunhos', function () {
    $me = mineRegister('eu@acme.com', 'ACME');
    mineSigner(mineEnvelope($me->tenant_id, 'Na lixeira', 'expired'), 'eu@acme.com');
    mineSigner(mineEnvelope($me->tenant_id, 'Arquivado', 'completed'), 'eu@acme.com');
    mineSigner(mineEnvelope($me->tenant_id, 'Rascunho', 'draft'), 'eu@acme.com');
    Envelope::query()->where('title', 'Na lixeira')->update(['trashed_at' => now()]);
    Envelope::query()->where('title', 'Arquivado')->update(['archived_at' => now()]);

    $this->get('/documentos/minhas-assinaturas')->assertOk()
        ->assertDontSee('Na lixeira')->assertDontSee('Arquivado')->assertDontSee('Rascunho');
});

it('quem já assinou vê o status Assinado e nenhum botão Assinar', function () {
    $me = mineRegister('eu@acme.com', 'ACME');
    $envelope = mineEnvelope($me->tenant_id, 'Já assinado', 'in_progress');
    $signer = mineSigner($envelope, 'eu@acme.com', 1, 'signed');

    $this->get('/documentos/minhas-assinaturas')->assertOk()
        ->assertSee('Já assinado')->assertSee('Assinado')
        ->assertDontSee($signer->signUrl(), false);
});

it('em ordem sequencial, quem não é a vez vê Aguardando a sua vez e nenhum link', function () {
    $me = mineRegister('eu@acme.com', 'ACME');
    $envelope = mineEnvelope($me->tenant_id, 'Fila de assinaturas', 'in_progress', 'sequential');
    mineSigner($envelope, 'primeiro@acme.com', 1);
    $mine = mineSigner($envelope, 'eu@acme.com', 2);

    $this->get('/documentos/minhas-assinaturas')->assertOk()
        ->assertSee('Aguardando a sua vez')
        ->assertDontSee($mine->signUrl(), false);
});

it('exige login', function () {
    $this->get('/documentos/minhas-assinaturas')->assertRedirect('/entrar');
});
