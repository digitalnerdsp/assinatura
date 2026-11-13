<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SenderSignature;
use App\Enums\SigningOrder;
use App\Models\ApiKey;
use App\Models\Envelope;
use App\Models\Tenant;
use App\Models\TenantSigningSetting;
use App\Models\User;
use App\Signing\ReportBuilder;
use App\Webhooks\EnvelopePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function fixOwner(string $email = 'dono@acme.com'): User
{
    test()->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono da Empresa', 'email' => $email,
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');
    $user = User::where('email', $email)->firstOrFail();
    $user->forceFill(['signature_type' => 'typed', 'signature_data' => 'Dono da Empresa'])->save();
    test()->actingAs($user->fresh());

    return $user->fresh();
}

function fixEnvelope(Tenant $tenant, array $people = [['name' => 'Parte', 'email' => 'parte@ex.com']], array $options = [], SigningOrder $order = SigningOrder::Sequential): Envelope
{
    Storage::fake('local');
    Queue::fake();
    Mail::fake();

    return app(EnvelopeService::class)->create($tenant, null, "%PDF-1.4\n%%EOF", 'a.pdf', 'Contrato', $people, $order, options: $options)->fresh();
}

function fixPaletteDataUrl(int $w, int $h): string
{
    $im = imagecreate($w, $h);
    imagecolorallocate($im, 255, 255, 255);
    ob_start();
    imagepng($im);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

// ---- I-1: identidade do remetente não muda se o envio falha

it('reenviar um envelope já enviado não reescreve a identidade de quem assinou', function () {
    $owner = fixOwner();
    $envelope = fixEnvelope($owner->tenant, options: ['sender' => ['name' => 'Dono da Empresa', 'email' => 'dono@acme.com']]);
    $this->post("/envelopes/{$envelope->id}/enviar")->assertRedirect();

    $colega = User::create(['name' => 'Colega', 'email' => 'colega@acme.com', 'password' => 'senha-muito-forte', 'tenant_id' => $owner->tenant_id, 'role' => 'member']);
    $colega->forceFill(['signature_type' => 'typed', 'signature_data' => 'Colega Assina'])->save();
    auth()->logout();
    $this->actingAs($colega->fresh());

    $this->from('/envelopes')->post("/envelopes/{$envelope->id}/enviar")->assertSessionHasErrors('envelope');

    $sender = $envelope->signers()->where('is_sender', true)->firstOrFail();
    expect($sender->name)->toBe('Dono da Empresa')->and($sender->email)->toBe('dono@acme.com')
        ->and($sender->signature->data)->toBe('Dono da Empresa');
});

// ---- I-2: a identidade do remetente é a de quem de fato assina, nos dois sentidos

it('rascunho criado no painel e enviado pela API assina como o representante', function () {
    Storage::fake('local');
    Queue::fake();
    Mail::fake();
    $tenant = Tenant::create(['name' => 'ACME']);
    TenantSigningSetting::create(['tenant_id' => $tenant->id, 'representative_name' => 'Maria Representante',
        'representative_email' => 'maria@acme.com', 'representative_cpf' => '123.456.789-09', 'delegation_accepted_at' => now()]);
    [, $plain] = ApiKey::issue($tenant, 'k', false, true);
    $envelope = fixEnvelope($tenant, options: ['sender' => ['name' => 'Fulano do Painel', 'email' => 'fulano@acme.com', 'cpf' => '999.999.999-99']]);

    $this->postJson("/api/v1/envelopes/{$envelope->id}/send", [], ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'])->assertOk();

    $sender = $envelope->signers()->where('is_sender', true)->firstOrFail();
    expect($sender->name)->toBe('Maria Representante')->and($sender->email)->toBe('maria@acme.com')
        ->and($sender->cpf)->toBe('123.456.789-09')->and($sender->signed_via)->toBe('api_key');
});

it('rascunho criado pela API e liberado no painel assina como quem liberou, sem herdar o CPF do representante', function () {
    $owner = fixOwner();
    $envelope = fixEnvelope($owner->tenant, options: ['sender' => ['name' => 'Maria Representante', 'email' => 'maria@acme.com', 'cpf' => '123.456.789-09']]);

    $this->post("/envelopes/{$envelope->id}/enviar")->assertRedirect();

    $sender = $envelope->signers()->where('is_sender', true)->firstOrFail();
    expect($sender->name)->toBe('Dono da Empresa')->and($sender->email)->toBe('dono@acme.com')->and($sender->cpf)->toBeNull();
});

// ---- I-3: o relatório diz como a empresa assinou

it('o relatório diz que a empresa assinou via integração ou no painel', function (string $via, string $expected) {
    $tenant = Tenant::create(['name' => 'ACME']);
    $envelope = fixEnvelope($tenant, options: ['sender' => ['name' => 'Empresa X', 'email' => 'x@ex.com']]);
    $envelope->signers()->where('is_sender', true)->update(['signed_via' => $via]);

    $report = app(ReportBuilder::class)->build($envelope->fresh());

    $sender = collect($report['signers'])->firstWhere('role', 'remetente');
    $other = collect($report['signers'])->firstWhere('role', 'signatário');
    expect($sender['method'])->toBe($expected)->and($other['method'])->toBeNull();
})->with([
    'api' => ['api_key', 'Assinado via integração (chave de API)'],
    'painel' => ['session', 'Assinado no painel (sessão autenticada)'],
]);

// ---- I-4: webhook com papel e remetente

it('o webhook identifica o papel e o remetente em cada signatário', function () {
    $tenant = Tenant::create(['name' => 'ACME']);
    $envelope = fixEnvelope($tenant, [
        ['name' => 'Parte', 'email' => 'parte@ex.com'],
        ['name' => 'Testemunha', 'email' => 't@ex.com', 'role' => 'witness'],
    ], ['sender' => ['name' => 'Empresa X', 'email' => 'x@ex.com']]);
    $witness = $envelope->signers()->where('role', 'witness')->firstOrFail();

    $payload = EnvelopePayload::make($envelope, $witness);

    $byName = collect($payload['envelope']['signers'])->keyBy('name');
    expect($byName['Empresa X']['is_sender'])->toBeTrue()->and($byName['Empresa X']['role'])->toBe('signer')
        ->and($byName['Testemunha']['role'])->toBe('witness')->and($byName['Parte']['is_sender'])->toBeFalse()
        ->and($payload['signer']['role'])->toBe('witness')->and($payload['signer']['is_sender'])->toBeFalse();
});

// ---- I-5: testemunha com position explícita não fura a fila

it('testemunha com position explícita nunca assina antes dos signatários', function () {
    $tenant = Tenant::create(['name' => 'ACME']);
    $envelope = fixEnvelope($tenant, [
        ['name' => 'Testemunha', 'email' => 't@ex.com', 'role' => 'witness', 'position' => 1],
        ['name' => 'Parte', 'email' => 'parte@ex.com', 'role' => 'signer', 'position' => 2],
    ]);
    app(EnvelopeService::class)->send($envelope);

    $witness = $envelope->signers()->where('role', 'witness')->firstOrFail();
    $party = $envelope->signers()->where('role', 'signer')->firstOrFail();
    expect($witness->position)->toBeGreaterThan($party->position)
        ->and($envelope->fresh()->activeSigners()->pluck('id')->all())->toBe([$party->id]);
});

// ---- I-6: envio e assinatura do remetente são atômicos; o remetente nunca ganha link

it('se a assinatura do remetente falha, o envio inteiro é desfeito', function () {
    $tenant = Tenant::create(['name' => 'ACME']);
    $envelope = fixEnvelope($tenant, options: ['sender' => ['name' => 'Empresa X', 'email' => 'x@ex.com']]);
    $envelope->signers()->where('is_sender', true)->update(['status' => 'signed']); // força a falha dentro de apply()

    try {
        app(EnvelopeService::class)->sendAndSignAsSender($envelope, new SenderSignature('typed', 'Empresa X', 'session', 1));
        $this->fail('deveria falhar');
    } catch (FlowException $e) {
        expect($e->reason)->toBe('signer_done');
    }

    $fresh = $envelope->fresh();
    expect($fresh->status->value)->toBe('draft')->and($fresh->sent_at)->toBeNull()
        ->and($fresh->evidenceEvents()->where('type', 'envelope_sent')->count())->toBe(0);
});

it('o remetente nunca recebe link, nem por gerar novo link nem por reenviar', function () {
    $tenant = Tenant::create(['name' => 'ACME']);
    $envelope = fixEnvelope($tenant, options: ['sender' => ['name' => 'Empresa X', 'email' => 'x@ex.com']]);
    app(EnvelopeService::class)->send($envelope); // sem assinar: simula o envio preso
    $sender = $envelope->signers()->where('is_sender', true)->firstOrFail();

    try {
        app(EnvelopeService::class)->regenerateLink($sender);
        $this->fail('deveria recusar');
    } catch (FlowException $e) {
        expect($e->reason)->toBe('sender_no_link');
    }
    expect($sender->fresh()->token_hash)->toBeNull()->and($sender->fresh()->token_encrypted)->toBeNull();
});

// ---- I-7: assinatura desenhada em tela larga

it('assinatura desenhada maior que o limite é reduzida em vez de recusada', function () {
    fixOwner();

    $this->put('/perfil/assinatura', ['type' => 'drawn', 'data' => fixPaletteDataUrl(2000, 900)])->assertRedirect();

    $saved = auth()->user()->fresh()->signature_data;
    [$w, $h] = getimagesizefromstring(base64_decode(substr($saved, strlen('data:image/png;base64,')), true));
    expect($w)->toBeLessThanOrEqual(1600)->and($h)->toBeLessThanOrEqual(800)->and($w)->toBeGreaterThan(100);
});

it('assinatura desenhada absurdamente grande continua recusada', function () {
    fixOwner();

    $this->from('/perfil')->put('/perfil/assinatura', ['type' => 'drawn', 'data' => fixPaletteDataUrl(5000, 3000)])->assertSessionHasErrors('data');
});
