<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SenderPolicy;
use App\Envelopes\SenderSignature;
use App\Envelopes\SigningService;
use App\Enums\SigningOrder;
use App\Jobs\SealEnvelope;
use App\Models\Envelope;
use App\Models\Tenant;
use App\Models\TenantSigningSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** @param list<array<string,mixed>> $people */
function senderEnvelope(array $people, SigningOrder $order = SigningOrder::Sequential, array $verifications = [], array $options = []): array
{
    Storage::fake('local');
    Queue::fake();
    Mail::fake();

    $tenant = Tenant::create(['name' => 'ACME']);
    $envelope = app(EnvelopeService::class)->create(
        $tenant, null, "%PDF-1.4\n%%EOF", 'a.pdf', 'Contrato', $people, $order,
        verifications: $verifications,
        options: $options + ['sender' => ['name' => 'Empresa X', 'email' => 'empresa@x.com']],
    );

    return [$tenant, $envelope->fresh()];
}

function sessionSignature(): SenderSignature
{
    return new SenderSignature('typed', 'Empresa X', 'session', 42);
}

it('o remetente assina ao liberar, sem verificações, e o próximo da fila é convidado', function () {
    [, $envelope] = senderEnvelope([['name' => 'Parte', 'email' => 'parte@ex.com']], verifications: ['selfie' => true]);
    $sender = $envelope->signers->firstWhere('is_sender', true);

    $tokens = app(EnvelopeService::class)->sendAndSignAsSender($envelope, sessionSignature());

    $sender->refresh();
    $other = $envelope->signers()->where('is_sender', false)->firstOrFail();
    expect($sender->status->value)->toBe('signed')
        ->and($sender->signed_via)->toBe('session')
        ->and($sender->signature->level)->toBe('simple')   // não herda o nível "avançado" do envelope
        ->and($tokens)->toHaveKey($other->id)->not->toHaveKey($sender->id);

    $types = $envelope->evidenceEvents()->where('signer_id', $sender->id)->pluck('type')->all();
    expect($types)->toBe(['document_signed']);
    $event = $envelope->evidenceEvents()->where('signer_id', $sender->id)->first();
    expect($event->payload['method'])->toBe('session')->and($event->payload['user_id'])->toBe(42);

    $fresh = $envelope->fresh();
    expect($fresh->status->value)->toBe('in_progress')
        ->and($fresh->activeSigners()->pluck('id')->all())->toBe([$other->id])
        ->and(app(SigningService::class)->pendingSteps($other->fresh()))->toContain('selfie');
});

it('envelope só do remetente conclui e sela na hora', function () {
    [, $envelope] = senderEnvelope([]);

    app(EnvelopeService::class)->sendAndSignAsSender($envelope, sessionSignature());

    expect($envelope->fresh()->status->value)->toBe('sealing');
    Queue::assertPushed(SealEnvelope::class);
});

it('o remetente não tem link público', function () {
    [, $envelope] = senderEnvelope([['name' => 'Parte', 'email' => 'parte@ex.com']]);
    app(EnvelopeService::class)->send($envelope);
    $sender = $envelope->signers()->where('is_sender', true)->firstOrFail();

    expect($sender->token_hash)->toBeNull()->and($sender->token_encrypted)->toBeNull();
    foreach (['', 'x', str_repeat('a', 48)] as $guess) {
        expect(fn () => app(SigningService::class)->findByToken($guess))->toThrow(FlowException::class);
    }
});

it('a posição da assinatura do remetente é gravada', function () {
    [, $envelope] = senderEnvelope([['name' => 'Parte', 'email' => 'parte@ex.com']], options: [
        'signature_fields' => [['signer' => 'sender', 'page' => 1, 'x' => 0.1, 'y' => 0.8], ['signer' => 0, 'page' => 1, 'x' => 0.5, 'y' => 0.8]],
    ]);

    $sender = $envelope->signers()->where('is_sender', true)->firstOrFail();
    $party = $envelope->signers()->where('is_sender', false)->firstOrFail();
    expect($envelope->signatureFields()->where('signer_id', $sender->id)->count())->toBe(1)
        ->and($envelope->signatureFields()->where('signer_id', $party->id)->count())->toBe(1);
});

it('a política decide quem assina', function () {
    $tenant = Tenant::create(['name' => 'ACME']);
    $policy = new SenderPolicy();

    expect($policy->policy($tenant))->toBe('always')
        ->and($policy->wantsSenderSignature($tenant, null))->toBeTrue()
        ->and($policy->wantsSenderSignature($tenant, true))->toBeTrue();
    expect(fn () => $policy->wantsSenderSignature($tenant, false))->toThrow(FlowException::class, 'Sua empresa exige');

    TenantSigningSetting::create(['tenant_id' => $tenant->id, 'sender_policy' => 'optional']);
    expect($policy->wantsSenderSignature($tenant, null))->toBeFalse()
        ->and($policy->wantsSenderSignature($tenant, true))->toBeTrue()
        ->and($policy->wantsSenderSignature($tenant, false))->toBeFalse();

    TenantSigningSetting::where('tenant_id', $tenant->id)->update(['sender_policy' => 'never']);
    expect($policy->wantsSenderSignature($tenant, null))->toBeFalse();
    try {
        $policy->wantsSenderSignature($tenant, true);
        $this->fail('never + true deveria recusar');
    } catch (FlowException $e) {
        expect($e->reason)->toBe('sender_signature_disabled');
    }
});

it('a exigência recusada carrega o código sender_signature_required', function () {
    $tenant = Tenant::create(['name' => 'ACME']);

    try {
        (new SenderPolicy())->wantsSenderSignature($tenant, false);
        $this->fail('always + false deveria recusar');
    } catch (FlowException $e) {
        expect($e->reason)->toBe('sender_signature_required');
    }
});

it('o remetente sempre vem primeiro em sequencial', function () {
    [, $envelope] = senderEnvelope([
        ['name' => 'Um', 'email' => 'um@ex.com'], ['name' => 'Dois', 'email' => 'dois@ex.com'],
    ]);

    $sender = $envelope->signers->firstWhere('is_sender', true);
    $others = $envelope->signers->where('is_sender', false);
    expect($sender->position)->toBe(0)->and($others->min('position'))->toBeGreaterThan(0);
});

it('sem remetente nem signatário continua exigindo um signatário', function () {
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'ACME']);

    try {
        app(EnvelopeService::class)->create($tenant, null, "%PDF-1.4\n%%EOF", 'a.pdf', 'C', []);
        $this->fail('deveria recusar');
    } catch (FlowException $e) {
        expect($e->reason)->toBe('no_signers');
    }
    expect(Envelope::count())->toBe(0);
});
