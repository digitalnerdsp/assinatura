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
}

it('o formulário tem a seção Testemunhas (opcional) com adicionar e remover', function () {
    witnessUiRegister();

    $this->get('/envelopes/novo')->assertOk()
        ->assertSee('Testemunhas (opcional)')->assertSee('Adicionar testemunha');
});

it('cria o envelope com testemunhas e mostra o papel na página', function () {
    Storage::fake('local');
    witnessUiRegister();

    $this->post('/envelopes', [
        'title' => 'Contrato com testemunhas', 'signing_order' => 'parallel',
        'document' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF"),
        'signers' => [['name' => 'Parte', 'email' => 'parte@ex.com']],
        'witnesses' => [
            ['name' => 'Testemunha Um', 'email' => 't1@ex.com'],
            ['name' => 'Testemunha Dois', 'email' => 't2@ex.com'],
        ],
    ])->assertRedirect();

    $envelope = Envelope::firstOrFail();
    expect($envelope->signers)->toHaveCount(3)
        ->and($envelope->signers->where('role', 'witness'))->toHaveCount(2);

    $this->get("/envelopes/{$envelope->id}")->assertOk()->assertSee('Testemunha Um')->assertSee('Testemunha');
});

it('recusa testemunha sem e-mail', function () {
    Storage::fake('local');
    witnessUiRegister();

    $this->from('/envelopes/novo')->post('/envelopes', [
        'title' => 'X', 'signing_order' => 'parallel',
        'document' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF"),
        'signers' => [['name' => 'Parte', 'email' => 'parte@ex.com']],
        'witnesses' => [['name' => 'Sem e-mail', 'email' => '']],
    ])->assertSessionHasErrors('witnesses.0.email');

    expect(Envelope::count())->toBe(0);
});

it('a API aceita role witness e devolve o papel', function () {
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'ACME']);
    [, $plain] = ApiKey::issue($tenant, 'k');
    $h = ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
    $base = [
        'title' => 'Contrato', 'document_base64' => base64_encode("%PDF-1.4\n%%EOF"), 'document_name' => 'a.pdf',
    ];

    $this->postJson('/api/v1/envelopes', $base + ['signers' => [
        ['name' => 'Ana', 'email' => 'ana@ex.com'],
        ['name' => 'Testemunha', 'email' => 't@ex.com', 'role' => 'witness'],
    ]], $h)->assertCreated()
        ->assertJsonPath('signers.0.role', 'signer')->assertJsonPath('signers.0.is_sender', false)
        ->assertJsonPath('signers.1.role', 'witness');

    $this->postJson('/api/v1/envelopes', $base + ['signers' => [
        ['name' => 'Ana', 'email' => 'ana@ex.com', 'role' => 'approver'],
    ]], $h)->assertStatus(422)->assertJsonStructure(['error', 'message', 'errors']);
});

it('o OpenAPI documenta role e os campos novos', function () {
    $json = $this->getJson('/api/v1/openapi.json')->assertOk()->getContent();

    expect($json)->toContain('witness')->toContain('is_sender')->toContain('signed_via');
});
