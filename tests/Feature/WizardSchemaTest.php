<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Envelope;
use App\Models\SignatureField;
use App\Models\Signer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function wzSchemaEnvelope(): Envelope
{
    $t = Tenant::create(['name' => 'Empresa']);
    $d = Document::create(['tenant_id' => $t->id, 'name' => 'a.pdf', 'storage_path' => 'x/a.pdf', 'sha256' => str_repeat('a', 64), 'size' => 8]);

    return Envelope::create(['tenant_id' => $t->id, 'document_id' => $d->id, 'title' => 'T', 'status' => 'draft', 'verification_code' => strtoupper(Str::random(12))])->refresh();
}

it('a migration cria as colunas do assistente', function () {
    expect(Schema::hasColumns('envelopes', ['wizard_step', 'allow_refuse']))->toBeTrue()
        ->and(Schema::hasColumns('signature_fields', ['type', 'value']))->toBeTrue()
        ->and(Schema::hasColumns('signatures', ['initials_type', 'initials_data']))->toBeTrue();
});

it('o rascunho nasce com a recusa permitida e fora do assistente', function () {
    $e = wzSchemaEnvelope();
    expect($e->allow_refuse)->toBeTrue()->and($e->wizard_step)->toBeNull();
});

it('o signatário pode existir sem e-mail enquanto é rascunho', function () {
    $e = wzSchemaEnvelope();
    $s = Signer::create(['envelope_id' => $e->id, 'name' => 'Ana', 'email' => null, 'role' => 'signer', 'position' => 1, 'status' => 'pending']);
    expect($s->refresh()->email)->toBeNull();
});

it('o campo de assinatura nasce do tipo assinatura', function () {
    $e = wzSchemaEnvelope();
    $s = Signer::create(['envelope_id' => $e->id, 'name' => 'Ana', 'email' => 'a@a.com', 'role' => 'signer', 'position' => 1, 'status' => 'pending']);
    $f = SignatureField::create(['envelope_id' => $e->id, 'signer_id' => $s->id, 'page' => 1, 'x' => 0.1, 'y' => 0.1])->refresh();
    expect($f->type)->toBe('signature')->and($f->value)->toBeNull()->and(SignatureField::TYPES)->toBe(['signature', 'initials', 'text']);
});
