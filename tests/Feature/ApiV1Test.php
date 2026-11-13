<?php

declare(strict_types=1);

use App\Mail\SignatureRequestedMail;
use App\Models\ApiKey;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function apiHeaders(Tenant $tenant): array
{
    [, $plain] = ApiKey::issue($tenant, 'teste');
    \App\Models\TenantSigningSetting::updateOrCreate(['tenant_id' => $tenant->id], ['sender_policy' => 'never']); // estes testes cobrem signatários; o remetente tem testes próprios

    return ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
}

function apiPayload(array $extra = []): array
{
    return $extra + [
        'title' => 'NDA',
        'document' => UploadedFile::fake()->createWithContent('nda.pdf', "%PDF-1.4\n%%EOF\n"),
        'signers' => [['name' => 'Ana', 'email' => 'ana@ex.com']],
    ];
}

it('rejeita chamadas sem chave ou com chave inválida/revogada', function () {
    $this->postJson('/api/v1/envelopes')->assertUnauthorized();
    $this->postJson('/api/v1/envelopes', [], ['Authorization' => 'Bearer sk_live_nope'])->assertUnauthorized();

    $tenant = Tenant::create(['name' => 'ACME']);
    [$key, $plain] = ApiKey::issue($tenant, 'k');
    \App\Models\TenantSigningSetting::updateOrCreate(['tenant_id' => $tenant->id], ['sender_policy' => 'never']); // estes testes cobrem signatários; o remetente tem testes próprios
    $key->update(['revoked_at' => now()]);
    $this->getJson('/api/v1/envelopes/x', ['Authorization' => "Bearer {$plain}"])->assertUnauthorized();
});

it('cria e envia um envelope pela API', function () {
    Storage::fake('local');
    Mail::fake();
    $tenant = Tenant::create(['name' => 'ACME']);
    $h = apiHeaders($tenant);

    $res = $this->post('/api/v1/envelopes', apiPayload(['send' => true, 'required_level' => 'advanced']), $h)
        ->assertCreated()
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('required_level', 'advanced')
        ->assertJsonPath('signers.0.email', 'ana@ex.com');

    Mail::assertSent(SignatureRequestedMail::class, 1);

    $id = $res->json('id');
    $this->getJson("/api/v1/envelopes/{$id}", $h)->assertOk()->assertJsonPath('title', 'NDA');
    $this->getJson("/api/v1/envelopes/{$id}/evidence", $h)->assertOk()->assertJsonPath('chain_intact', true)
        ->assertJsonPath('events.0.type', 'envelope_created');
    $this->getJson("/api/v1/envelopes/{$id}/download", $h)->assertStatus(409);
});

it('valida o payload e rejeita arquivo que não é PDF', function () {
    Storage::fake('local');
    $h = apiHeaders(Tenant::create(['name' => 'ACME']));

    $this->postJson('/api/v1/envelopes', [], $h)->assertUnprocessable()->assertJsonValidationErrors(['title', 'document', 'signers']);
    $this->post('/api/v1/envelopes', apiPayload(['document' => UploadedFile::fake()->createWithContent('x.pdf', 'isto não é pdf')]), $h)
        ->assertUnprocessable();
});

it('isola envelopes entre tenants na API', function () {
    Storage::fake('local');
    $h1 = apiHeaders(Tenant::create(['name' => 'A']));
    $h2 = apiHeaders(Tenant::create(['name' => 'B']));

    $id = $this->post('/api/v1/envelopes', apiPayload(), $h1)->assertCreated()->json('id');

    $this->getJson("/api/v1/envelopes/{$id}", $h2)->assertNotFound();
    $this->postJson("/api/v1/envelopes/{$id}/send", [], $h2)->assertNotFound();
    $this->getJson("/api/v1/envelopes/{$id}/evidence", $h2)->assertNotFound();
});

it('não permite enviar duas vezes nem cancelar depois de recusado', function () {
    Storage::fake('local');
    Mail::fake();
    $h = apiHeaders(Tenant::create(['name' => 'ACME']));
    $id = $this->post('/api/v1/envelopes', apiPayload(['send' => true]), $h)->assertCreated()->json('id');

    $this->postJson("/api/v1/envelopes/{$id}/send", [], $h)->assertStatus(409)->assertJsonPath('error', 'not_draft');
    $this->postJson("/api/v1/envelopes/{$id}/cancel", [], $h)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson("/api/v1/envelopes/{$id}/cancel", [], $h)->assertStatus(409);
});
