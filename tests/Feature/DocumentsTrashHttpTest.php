<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function docsRegister(string $email = 'dono@acme.com', string $company = 'ACME'): User
{
    test()->post('/cadastro', [
        'company' => $company, 'name' => 'Dono', 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', $email)->firstOrFail();
}

function docsEnvelope(User $user, string $status, string $title = 'Contrato'): Envelope
{
    $path = 'documents/'.Str::uuid().'.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4');
    $doc = Document::create(['tenant_id' => $user->tenant_id, 'name' => 'a.pdf', 'storage_path' => $path, 'sha256' => str_repeat('a', 64), 'size' => 8]);

    return Envelope::create([
        'tenant_id' => $user->tenant_id, 'created_by' => $user->id, 'document_id' => $doc->id, 'title' => $title,
        'status' => $status, 'verification_code' => strtoupper(Str::random(12)),
    ]);
}

it('a lista Documentos criados não mostra lixeira nem arquivados', function () {
    Storage::fake('local');
    $user = docsRegister();
    docsEnvelope($user, 'draft', 'Ativo visível');
    docsEnvelope($user, 'expired', 'Na lixeira')->forceFill(['trashed_at' => now()])->save();
    docsEnvelope($user, 'completed', 'Arquivado')->forceFill(['archived_at' => now()])->save();

    $this->get('/envelopes')->assertOk()
        ->assertSee('Documentos criados')->assertSee('Criar documento')
        ->assertSee('Ativo visível')->assertDontSee('Na lixeira')->assertDontSee('Arquivado');

    $this->get('/envelopes?arquivados=1')->assertOk()
        ->assertSee('Arquivado')->assertDontSee('Ativo visível')->assertDontSee('Na lixeira');
});

it('move rascunho para a lixeira e lista em /documentos/lixeira', function () {
    Storage::fake('local');
    $user = docsRegister();
    $envelope = docsEnvelope($user, 'draft', 'Rascunho X');

    $this->post("/envelopes/{$envelope->id}/lixeira")->assertRedirect()->assertSessionHas('status', 'Movido para a lixeira.');

    $this->get('/documentos/lixeira')->assertOk()->assertSee('Rascunho X')->assertSee('apagado em 30 dias');
});

it('não deixa mover para a lixeira um envelope em andamento ou concluído', function (string $status) {
    Storage::fake('local');
    $user = docsRegister();
    $envelope = docsEnvelope($user, $status);

    $this->from('/envelopes')->post("/envelopes/{$envelope->id}/lixeira")->assertSessionHasErrors('envelope');

    expect($envelope->fresh()->trashed_at)->toBeNull();
})->with(['sent', 'in_progress', 'completed']);

it('restaura da lixeira sem reabrir o envelope', function () {
    Storage::fake('local');
    $user = docsRegister();
    $envelope = docsEnvelope($user, 'expired');
    $this->post("/envelopes/{$envelope->id}/lixeira");

    $this->post("/envelopes/{$envelope->id}/restaurar")->assertRedirect();

    $fresh = $envelope->fresh();
    expect($fresh->trashed_at)->toBeNull()->and($fresh->status->value)->toBe('expired')
        ->and($fresh->status->isOpenForSigning())->toBeFalse();
});

it('arquiva concluído e permite desarquivar', function () {
    Storage::fake('local');
    $user = docsRegister();
    $envelope = docsEnvelope($user, 'completed');

    $this->post("/envelopes/{$envelope->id}/arquivar")->assertRedirect();
    expect($envelope->fresh()->isArchived())->toBeTrue();

    $this->post("/envelopes/{$envelope->id}/desarquivar")->assertRedirect();
    expect($envelope->fresh()->isArchived())->toBeFalse();
});

it('POST de outra empresa devolve 404', function (string $route) {
    Storage::fake('local');
    $dono = docsRegister('dono@acme.com', 'ACME');
    $envelope = docsEnvelope($dono, 'draft');
    auth()->logout();
    docsRegister('outro@beta.com', 'Beta');

    $this->post("/envelopes/{$envelope->id}/{$route}")->assertNotFound();
    expect($envelope->fresh()->trashed_at)->toBeNull();
})->with(['lixeira', 'restaurar', 'arquivar', 'desarquivar']);

it('a API não lista envelope na lixeira', function () {
    Storage::fake('local');
    $user = docsRegister();
    $kept = docsEnvelope($user, 'draft', 'Mantido');
    $gone = docsEnvelope($user, 'expired', 'Descartado');
    $gone->forceFill(['trashed_at' => now()])->save();
    [, $plain] = ApiKey::issue($user->tenant, 'k');

    $ids = collect($this->withHeaders(['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'])
        ->getJson('/api/v1/envelopes')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($kept->id)->not->toContain($gone->id);
});

it('a página do envelope oferece Mover para a lixeira (rascunho) e Arquivar (concluído)', function () {
    Storage::fake('local');
    $user = docsRegister();
    $draft = docsEnvelope($user, 'draft');
    $done = docsEnvelope($user, 'completed');

    $this->get("/envelopes/{$draft->id}")->assertOk()->assertSee('Mover para a lixeira')->assertDontSee('Arquivar');
    $this->get("/envelopes/{$done->id}")->assertOk()->assertSee('Arquivar')->assertDontSee('Mover para a lixeira');
});

it('um rascunho na lixeira não pode ser enviado e a página oferece Restaurar', function () {
    Storage::fake('local');
    $user = docsRegister();
    $envelope = docsEnvelope($user, 'draft', 'Rascunho descartado');
    $this->post("/envelopes/{$envelope->id}/lixeira");

    $this->from('/envelopes')->post("/envelopes/{$envelope->id}/enviar")->assertSessionHasErrors('envelope');
    expect($envelope->fresh()->status->value)->toBe('draft');

    $this->get("/envelopes/{$envelope->id}")->assertOk()->assertSee('Este documento está na lixeira')->assertSee('Restaurar');
});

it('o Painel não lista envelopes da lixeira', function () {
    Storage::fake('local');
    $user = docsRegister();
    docsEnvelope($user, 'draft', 'Visível no painel');
    docsEnvelope($user, 'expired', 'Descartado do painel')->forceFill(['trashed_at' => now()])->save();

    $this->get('/painel')->assertOk()->assertSee('Visível no painel')->assertDontSee('Descartado do painel');
});
