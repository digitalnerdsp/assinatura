<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Evidence\EvidenceLogger;
use App\Evidence\RequestContext;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function newEnvelope(): App\Models\Envelope
{
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'ACME']);

    return app(EnvelopeService::class)->create(
        $tenant,
        null,
        "%PDF-1.4\n%teste\n",
        'contrato.pdf',
        'Contrato',
        [['name' => 'Ana', 'email' => 'ana@example.com']],
    );
}

it('encadeia os eventos e a cadeia fica íntegra', function () {
    $envelope = newEnvelope();
    $logger = app(EvidenceLogger::class);

    $logger->record($envelope, 'teste_a', ['b' => 2, 'a' => 1], null, new RequestContext('203.0.113.7', 'PestAgent'));
    $logger->record($envelope, 'teste_b', ['nested' => ['z' => 1, 'y' => [1, 2]]]);

    $events = $envelope->evidenceEvents()->get();

    expect($events)->toHaveCount(3) // envelope_created + 2
        ->and($events[1]->prev_hash)->toBe($events[0]->hash)
        ->and($events[2]->prev_hash)->toBe($events[1]->hash)
        ->and($logger->isIntact($envelope))->toBeTrue();
});

it('bloqueia UPDATE e DELETE na trilha de evidências', function () {
    $envelope = newEnvelope();

    // DB::transaction aninhado = savepoint, para não abortar a transação do teste.
    expect(fn () => DB::transaction(fn () => DB::table('evidence_events')->update(['type' => 'x'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('evidence_events')->delete()))->toThrow(QueryException::class);
    expect($envelope->evidenceEvents()->first()->type)->toBe('envelope_created');
});

it('detecta adulteração mesmo se o trigger for contornado', function () {
    $envelope = newEnvelope();
    $logger = app(EvidenceLogger::class);
    $logger->record($envelope, 'teste_a', ['k' => 'v']);

    DB::statement('ALTER TABLE evidence_events DISABLE TRIGGER evidence_events_no_update');
    DB::table('evidence_events')->where('type', 'teste_a')->update(['payload' => json_encode(['k' => 'ADULTERADO'])]);
    DB::statement('ALTER TABLE evidence_events ENABLE TRIGGER evidence_events_no_update');

    expect($logger->isIntact($envelope))->toBeFalse()
        ->and($logger->firstBrokenSequence($envelope))->toBe(2);
});
