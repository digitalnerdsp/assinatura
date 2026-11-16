<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Envelope;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function witnessUiRegister(): void
{
    test()->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => 'dono@acme.com',
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');
    \App\Models\TenantSigningSetting::updateOrCreate(['tenant_id' => \App\Models\User::firstOrFail()->tenant_id], ['sender_policy' => 'never']);
}

it('o passo dos signatários oferece a função Assinar como testemunha', function () {
    Storage::fake('local');
    witnessUiRegister();
    $e = wzWeb();

    $this->get("/documentos/{$e->id}/passo/2")->assertOk()->assertSee('Assinar como testemunha');
});

it('cria o envelope com testemunhas e mostra o papel na página', function () {
    Storage::fake('local');
    witnessUiRegister();

    $envelope = wzWeb([
        'title' => 'Contrato com testemunhas',
        'people' => [
            ['name' => 'Parte', 'email' => 'parte@ex.com'],
            ['name' => 'Testemunha Um', 'email' => 't1@ex.com', 'role' => 'witness'],
            ['name' => 'Testemunha Dois', 'email' => 't2@ex.com', 'role' => 'witness'],
        ],
    ]);
    expect($envelope->signers)->toHaveCount(3)
        ->and($envelope->signers->where('role', 'witness'))->toHaveCount(2);

    $this->get("/documentos/{$envelope->id}")->assertOk()->assertSee('Testemunha Um')->assertSee('Testemunha');
});

it('com envio automático a testemunha sem e-mail barra o envio', function () {
    Storage::fake('local');
    witnessUiRegister();
    $e = wzWeb(['title' => 'X', 'people' => [['name' => 'Parte', 'email' => 'parte@ex.com'], ['name' => 'Sem e-mail', 'role' => 'witness']]]);
    $witness = $e->signers()->where('role', 'witness')->firstOrFail();

    $this->post("/documentos/{$e->id}/finalizar", ['auto' => 1])->assertSessionHasErrors("emails.{$witness->id}");

    expect($e->fresh()->status->value)->toBe('draft');
});

it('a API aceita role witness e devolve o papel', function () {
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'ACME']);
    [, $plain] = ApiKey::issue($tenant, 'k');
    \App\Models\TenantSigningSetting::updateOrCreate(['tenant_id' => $tenant->id], ['sender_policy' => 'never']); // estes testes cobrem signatários; o remetente tem testes próprios
    $h = ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
    $base = [
        'title' => 'Contrato', 'document_base64' => base64_encode("%PDF-1.4\n%%EOF"), 'document_name' => 'a.pdf',
    ];

    $this->postJson('/api/v1/documents', $base + ['signers' => [
        ['name' => 'Ana', 'email' => 'ana@ex.com'],
        ['name' => 'Testemunha', 'email' => 't@ex.com', 'role' => 'witness'],
    ]], $h)->assertCreated()
        ->assertJsonPath('signers.0.role', 'signer')->assertJsonPath('signers.0.is_sender', false)
        ->assertJsonPath('signers.1.role', 'witness');

    $this->postJson('/api/v1/documents', $base + ['signers' => [
        ['name' => 'Ana', 'email' => 'ana@ex.com', 'role' => 'approver'],
    ]], $h)->assertStatus(422)->assertJsonStructure(['error', 'message', 'errors']);
});

it('o OpenAPI documenta role e os campos novos', function () {
    $json = $this->getJson('/api/v1/openapi.json')->assertOk()->getContent();

    expect($json)->toContain('witness')->toContain('is_sender')->toContain('signed_via');
});
