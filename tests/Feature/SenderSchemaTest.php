<?php

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Tenant;
use App\Models\TenantSigningSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('cria as colunas novas com os padrões do escopo', function () {
    expect(Schema::hasColumns('signers', ['is_sender', 'signed_via']))->toBeTrue()
        ->and(Schema::hasColumns('tenant_signing_settings', [
            'sender_policy', 'representative_name', 'representative_email', 'representative_cpf',
            'delegation_accepted_at', 'delegation_accepted_by', 'delegation_accepted_ip',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('api_keys', 'can_sign_as_company'))->toBeTrue()
        ->and(Schema::hasColumns('users', ['signature_type', 'signature_data']))->toBeTrue();

    $tenant = Tenant::create(['name' => 'ACME']);
    $setting = TenantSigningSetting::create(['tenant_id' => $tenant->id])->fresh();
    expect($setting->sender_policy)->toBe('always');

    [$key] = ApiKey::issue($tenant, 'k');
    expect($key->fresh()->can_sign_as_company)->toBeFalse();
});

it('identifica remetente e testemunha no signatário', function () {
    $signer = new Signer();
    expect($signer->isSender())->toBeFalse()->and($signer->isWitness())->toBeFalse();

    $signer->forceFill(['role' => 'witness', 'is_sender' => true]);
    expect($signer->isWitness())->toBeTrue()->and($signer->isSender())->toBeTrue();
});

it('configura o limite de testemunhas e os papéis participantes', function () {
    expect(config('signing.max_witnesses'))->toBe(10)
        ->and(Envelope::PARTICIPANT_ROLES)->toBe(['signer', 'witness']);
});
