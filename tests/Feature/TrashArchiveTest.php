<?php

declare(strict_types=1);

use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\TrashService;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\SignerAttachment;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function trashEnvelope(string $status, ?Tenant $tenant = null): Envelope
{
    $tenant ??= Tenant::create(['name' => 'ACME']);
    $path = 'documents/'.Str::uuid().'.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 teste');
    $doc = Document::create(['tenant_id' => $tenant->id, 'name' => 'a.pdf', 'storage_path' => $path, 'sha256' => str_repeat('a', 64), 'size' => 14]);

    return Envelope::create([
        'tenant_id' => $tenant->id, 'document_id' => $doc->id, 'title' => 'Contrato '.$status,
        'status' => $status, 'verification_code' => strtoupper(Str::random(12)),
    ]);
}

it('move para a lixeira rascunho, cancelado e expirado', function (string $status) {
    Storage::fake('local');
    $envelope = trashEnvelope($status);

    app(TrashService::class)->trash($envelope);

    expect($envelope->fresh()->trashed_at)->not->toBeNull()->and($envelope->fresh()->isTrashed())->toBeTrue();
})->with(['draft', 'cancelled', 'expired']);

it('recusa lixeira para sent, in_progress, sealing, completed e refused', function (string $status) {
    Storage::fake('local');
    $envelope = trashEnvelope($status);

    try {
        app(TrashService::class)->trash($envelope);
        $this->fail('deveria recusar');
    } catch (FlowException $e) {
        expect($e->reason)->toBe('not_trashable');
    }

    expect($envelope->fresh()->trashed_at)->toBeNull();
})->with(['sent', 'in_progress', 'sealing', 'completed', 'refused']);

it('arquiva concluído e recusado', function (string $status) {
    Storage::fake('local');
    $envelope = trashEnvelope($status);

    app(TrashService::class)->archive($envelope);

    expect($envelope->fresh()->isArchived())->toBeTrue();
})->with(['completed', 'refused']);

it('recusa arquivar os demais status', function (string $status) {
    Storage::fake('local');
    $envelope = trashEnvelope($status);

    expect(fn () => app(TrashService::class)->archive($envelope))
        ->toThrow(FlowException::class);
    expect($envelope->fresh()->archived_at)->toBeNull();
})->with(['draft', 'sent', 'in_progress', 'sealing', 'cancelled', 'expired']);

it('restaura e desarquiva sem mudar o status', function () {
    Storage::fake('local');
    $svc = app(TrashService::class);

    $expired = trashEnvelope('expired');
    $svc->trash($expired);
    $svc->restore($expired->fresh());
    expect($expired->fresh()->trashed_at)->toBeNull()->and($expired->fresh()->status->value)->toBe('expired');

    $done = trashEnvelope('completed');
    $svc->archive($done);
    $svc->unarchive($done->fresh());
    expect($done->fresh()->archived_at)->toBeNull();

    try {
        $svc->restore($expired->fresh());
        $this->fail('deveria recusar');
    } catch (FlowException $e) {
        expect($e->reason)->toBe('not_trashed');
    }
});

it('purge apaga só o que está há 30 dias ou mais na lixeira', function () {
    Storage::fake('local');
    $old = trashEnvelope('expired');
    $old->forceFill(['trashed_at' => now()->subDays(31)])->save();
    $recent = trashEnvelope('expired');
    $recent->forceFill(['trashed_at' => now()->subDays(10)])->save();
    $oldPath = $old->document->storage_path;

    $removed = app(TrashService::class)->purge(30);

    expect($removed)->toBe(1)
        ->and(Envelope::find($old->id))->toBeNull()
        ->and(Document::find($old->document_id))->toBeNull()
        ->and(Envelope::find($recent->id))->not->toBeNull();
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($recent->document->storage_path);
});

it('purge remove também as fotos e selfies dos signatários', function () {
    Storage::fake('local');
    $envelope = trashEnvelope('expired');
    $signer = Signer::create(['envelope_id' => $envelope->id, 'name' => 'Ana', 'email' => 'ana@ex.com']);
    Storage::disk('local')->put('attachments/selfie.jpg', 'x');
    SignerAttachment::create(['signer_id' => $signer->id, 'kind' => 'selfie', 'path' => 'attachments/selfie.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'sha256' => str_repeat('b', 64)]);
    $envelope->forceFill(['trashed_at' => now()->subDays(40)])->save();

    app(TrashService::class)->purge(30);

    Storage::disk('local')->assertMissing('attachments/selfie.jpg');
    expect(Signer::find($signer->id))->toBeNull();
});

it('purge nunca apaga concluído, mesmo com trashed_at antigo', function () {
    Storage::fake('local');
    $done = trashEnvelope('completed');
    $done->forceFill(['trashed_at' => now()->subDays(90)])->save();

    expect(app(TrashService::class)->purge(30))->toBe(0)
        ->and(Envelope::find($done->id))->not->toBeNull();
    Storage::disk('local')->assertExists($done->document->storage_path);
});

it('agenda a limpeza diária', function () {
    $this->artisan('envelopes:purge-trash')->expectsOutputToContain('envelope(s)')->assertExitCode(0);

    $scheduled = collect(app(Schedule::class)->events())
        ->contains(fn ($e) => str_contains((string) $e->command, 'envelopes:purge-trash'));
    expect($scheduled)->toBeTrue();
});
