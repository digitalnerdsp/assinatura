<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Envelope;
use App\Models\Tenant;
use App\Models\TenantSigningSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const COMPANY_REP = [
    'representative_name' => 'Maria Representante', 'representative_email' => 'maria@acme.com',
    'representative_cpf' => '123.456.789-09',
];

/** @return array{0:Tenant,1:array<string,string>,2:ApiKey} */
function companyApi(?array $setting = null, bool $canSign = false, ?Tenant $tenant = null): array
{
    Storage::fake('local');
    Queue::fake();
    Mail::fake();
    $tenant ??= Tenant::create(['name' => 'ACME']);
    [$key, $plain] = ApiKey::issue($tenant, 'k', false, $canSign);
    if ($setting !== null) {
        TenantSigningSetting::updateOrCreate(['tenant_id' => $tenant->id], $setting);
    }

    return [$tenant, ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'], $key];
}

function companyPayload(array $extra = []): array
{
    return $extra + [
        'title' => 'Contrato', 'document_base64' => base64_encode("%PDF-1.4\n%%EOF"), 'document_name' => 'a.pdf',
        'signers' => [['name' => 'Ana', 'email' => 'ana@ex.com']],
    ];
}

function acceptedRepresentative(array $extra = []): array
{
    return COMPANY_REP + ['delegation_accepted_at' => now()] + $extra;
}

it('chave sem permissão recusa com 422 e não cria envelope', function () {
    [, $h] = companyApi(acceptedRepresentative(), canSign: false);

    $this->postJson('/api/v1/envelopes', companyPayload(), $h)
        ->assertStatus(422)->assertJsonPath('error', 'sender_signature_not_allowed');

    expect(Envelope::count())->toBe(0);
});

it('sem representante ou sem aceite do termo recusa e não cria envelope', function (?array $setting) {
    [, $h] = companyApi($setting, canSign: true);

    $this->postJson('/api/v1/envelopes', companyPayload(), $h)
        ->assertStatus(422)->assertJsonPath('error', 'no_company_representative');

    expect(Envelope::count())->toBe(0);
})->with([
    'sem nenhuma configuração' => [null],
    'representante sem aceite' => [COMPANY_REP],
    'aceite sem representante' => [['delegation_accepted_at' => now()]],
]);

it('com tudo configurado cria, e o envio assina pela chave', function () {
    [$tenant, $h, $key] = companyApi(acceptedRepresentative(), canSign: true);

    $id = $this->postJson('/api/v1/envelopes', companyPayload(), $h)->assertCreated()
        ->assertJsonPath('signers.0.is_sender', true)->assertJsonPath('signers.0.name', 'Maria Representante')
        ->assertJsonPath('signers.0.status', 'pending')->json('id');

    $this->postJson("/api/v1/envelopes/{$id}/send", [], $h)->assertOk();

    $sender = Envelope::findOrFail($id)->signers()->where('is_sender', true)->firstOrFail();
    expect($sender->status->value)->toBe('signed')->and($sender->signed_via)->toBe('api_key')
        ->and($sender->signature->data)->toBe('Maria Representante')->and($sender->signature->level)->toBe('simple');

    $event = $sender->envelope->evidenceEvents()->where('signer_id', $sender->id)->where('type', 'document_signed')->firstOrFail();
    expect($event->payload['method'])->toBe('api_key')->and($event->payload['api_key_id'])->toBe($key->id)
        ->and($event->ip)->not->toBeNull();
    expect($sender->envelope->evidenceEvents()->where('signer_id', $sender->id)->pluck('type')->all())->toBe(['document_signed']);
});

it('send:true na criação já assina como empresa', function () {
    [, $h] = companyApi(acceptedRepresentative(), canSign: true);

    $id = $this->postJson('/api/v1/envelopes', companyPayload(['send' => true]), $h)->assertCreated()
        ->assertJsonPath('signers.0.status', 'signed')->assertJsonPath('signers.0.signed_via', 'api_key')->json('id');

    expect(Envelope::findOrFail($id)->status->value)->toBe('in_progress');
});

it('sender_signs=false só vale com a política optional', function () {
    [, $h] = companyApi(acceptedRepresentative(), canSign: true);
    $this->postJson('/api/v1/envelopes', companyPayload(['sender_signs' => false]), $h)
        ->assertStatus(422)->assertJsonPath('error', 'sender_signature_required');
    expect(Envelope::count())->toBe(0);

    [, $h2] = companyApi(acceptedRepresentative(['sender_policy' => 'optional']), canSign: true);
    $this->postJson('/api/v1/envelopes', companyPayload(['sender_signs' => false]), $h2)->assertCreated()
        ->assertJsonPath('signers.0.is_sender', false);
    expect(Envelope::first()->signers()->where('is_sender', true)->count())->toBe(0);
});

it('com política never, sender_signs=true é recusado', function () {
    [, $h] = companyApi(['sender_policy' => 'never'], canSign: true);

    $this->postJson('/api/v1/envelopes', companyPayload(['sender_signs' => true]), $h)
        ->assertStatus(422)->assertJsonPath('error', 'sender_signature_disabled');
});

it('o envio reconfere a permissão da chave usada', function () {
    [$tenant, $allowed] = companyApi(acceptedRepresentative(), canSign: true);
    [, $denied] = companyApi(null, canSign: false, tenant: $tenant);

    $id = $this->postJson('/api/v1/envelopes', companyPayload(), $allowed)->assertCreated()->json('id');

    $this->postJson("/api/v1/envelopes/{$id}/send", [], $denied)
        ->assertStatus(422)->assertJsonPath('error', 'sender_signature_not_allowed');

    expect(Envelope::findOrFail($id)->status->value)->toBe('draft');
});

it('a chave de outra empresa nunca usa o representante desta', function () {
    companyApi(acceptedRepresentative(), canSign: true); // empresa A, com representante
    [, $hB] = companyApi(null, canSign: true, tenant: Tenant::create(['name' => 'Outra']));

    $this->postJson('/api/v1/envelopes', companyPayload(), $hB)
        ->assertStatus(422)->assertJsonPath('error', 'no_company_representative');

    expect(Envelope::count())->toBe(0);
});

it('o painel emite chave com a permissão e mostra o aviso', function () {
    $this->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => 'dono@acme.com',
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    $this->post('/api-keys', ['name' => 'Hospedagem', 'can_sign_as_company' => 1])->assertRedirect();

    expect(ApiKey::firstOrFail()->can_sign_as_company)->toBeTrue();
    $this->get('/api-keys')->assertOk()->assertSee('Assina pela empresa');
    $this->get('/configuracoes/assinatura-da-empresa')->assertOk()->assertSee('Representante');
});

it('o /me e o OpenAPI expõem os campos novos', function () {
    [, $h] = companyApi(null, canSign: true);

    $this->getJson('/api/v1/me', $h)->assertOk()->assertJsonPath('key.can_sign_as_company', true);
    $spec = $this->getJson('/api/v1/openapi.json')->assertOk()->getContent();
    expect($spec)->toContain('sender_signs')->toContain('sender_signature_not_allowed')->toContain('no_company_representative');
});

// ---- Tela "Assinatura da empresa"

function companyOwner(string $email = 'dono@acme.com'): User
{
    test()->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');

    return User::where('email', $email)->firstOrFail();
}

it('o owner salva política, representante e aceita o termo (registrando quem, quando e IP)', function () {
    $owner = companyOwner();

    $this->put('/configuracoes/assinatura-da-empresa', COMPANY_REP + [
        'sender_policy' => 'optional', 'accept_delegation' => 1,
    ])->assertRedirect();

    $s = TenantSigningSetting::where('tenant_id', $owner->tenant_id)->firstOrFail();
    expect($s->sender_policy)->toBe('optional')->and($s->representative_name)->toBe('Maria Representante')
        ->and($s->delegation_accepted_at)->not->toBeNull()->and($s->delegation_accepted_by)->toBe($owner->id)
        ->and($s->delegation_accepted_ip)->not->toBeNull();
});

it('trocar o representante apaga o aceite do termo', function () {
    $owner = companyOwner();
    $this->put('/configuracoes/assinatura-da-empresa', COMPANY_REP + ['sender_policy' => 'always', 'accept_delegation' => 1]);

    $this->put('/configuracoes/assinatura-da-empresa', [
        'sender_policy' => 'always', 'representative_name' => 'Outra Pessoa', 'representative_email' => 'outra@acme.com',
        'representative_cpf' => '111.222.333-44',
    ])->assertRedirect();

    $s = TenantSigningSetting::where('tenant_id', $owner->tenant_id)->firstOrFail();
    expect($s->representative_name)->toBe('Outra Pessoa')->and($s->delegation_accepted_at)->toBeNull()
        ->and($s->delegation_accepted_by)->toBeNull();
});

it('salvar sem mudar o representante mantém o aceite', function () {
    $owner = companyOwner();
    $this->put('/configuracoes/assinatura-da-empresa', COMPANY_REP + ['sender_policy' => 'always', 'accept_delegation' => 1]);

    $this->put('/configuracoes/assinatura-da-empresa', COMPANY_REP + ['sender_policy' => 'never'])->assertRedirect();

    $s = TenantSigningSetting::where('tenant_id', $owner->tenant_id)->firstOrFail();
    expect($s->sender_policy)->toBe('never')->and($s->delegation_accepted_at)->not->toBeNull();
});

it('recusa política inválida e e-mail inválido do representante', function () {
    companyOwner();

    $this->from('/configuracoes/assinatura-da-empresa')->put('/configuracoes/assinatura-da-empresa', [
        'sender_policy' => 'talvez', 'representative_name' => 'X', 'representative_email' => 'nao-e-email',
    ])->assertSessionHasErrors(['sender_policy', 'representative_email']);
});

it('só o owner altera a assinatura da empresa', function () {
    $owner = companyOwner();
    $member = User::create(['name' => 'Colega', 'email' => 'colega@acme.com', 'password' => 'senha-muito-forte', 'tenant_id' => $owner->tenant_id, 'role' => 'member']);
    auth()->logout();
    $this->actingAs($member);

    $this->put('/configuracoes/assinatura-da-empresa', COMPANY_REP + ['sender_policy' => 'never', 'accept_delegation' => 1])->assertForbidden();

    expect(TenantSigningSetting::where('tenant_id', $owner->tenant_id)->exists())->toBeFalse();
});
