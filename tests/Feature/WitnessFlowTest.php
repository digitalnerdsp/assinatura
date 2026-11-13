<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SigningService;
use App\Enums\SigningOrder;
use App\Jobs\SealEnvelope;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** @param list<array<string,mixed>> $people */
function witnessEnvelope(array $people, SigningOrder $order = SigningOrder::Parallel, bool $send = true): Envelope
{
    Storage::fake('local');
    Queue::fake();
    Mail::fake();

    $tenant = Tenant::create(['name' => 'ACME']);
    $envelope = app(EnvelopeService::class)->create($tenant, null, "%PDF-1.4\n%%EOF", 'a.pdf', 'Contrato', $people, $order);
    if ($send) {
        app(EnvelopeService::class)->send($envelope);
    }

    return $envelope->fresh();
}

function witnessPeople(int $witnesses = 2): array
{
    $people = [['name' => 'Parte', 'email' => 'parte@ex.com', 'role' => 'signer']];
    for ($i = 1; $i <= $witnesses; $i++) {
        $people[] = ['name' => "Testemunha {$i}", 'email' => "t{$i}@ex.com", 'role' => 'witness'];
    }

    return $people;
}

function signAs(Signer $signer): void
{
    app(SigningService::class)->sign($signer->fresh(), 'typed', $signer->name, true);
}

it('em envelope paralelo todos assinam juntos, testemunhas incluídas', function () {
    $envelope = witnessEnvelope(witnessPeople());

    expect($envelope->activeSigners())->toHaveCount(3);
});

it('em sequencial a testemunha só assina depois dos signatários', function () {
    $envelope = witnessEnvelope(witnessPeople(), SigningOrder::Sequential);
    $party = $envelope->signers->firstWhere('role', 'signer');
    $witnesses = $envelope->signers->where('role', 'witness');

    expect($envelope->activeSigners()->pluck('id')->all())->toBe([$party->id]);
    expect($witnesses->pluck('position')->unique()->all())->toBe([$party->position + 1]);

    try {
        signAs($witnesses->first());
        $this->fail('a testemunha não pode assinar antes do signatário');
    } catch (FlowException $e) {
        expect($e->reason)->toBe('not_your_turn');
    }

    signAs($party);
    expect($envelope->fresh()->activeSigners()->pluck('id')->sort()->values()->all())
        ->toBe($witnesses->pluck('id')->sort()->values()->all());
});

it('só conclui quando signatários e testemunhas assinaram', function () {
    $envelope = witnessEnvelope(witnessPeople());
    $party = $envelope->signers->firstWhere('role', 'signer');
    [$w1, $w2] = $envelope->signers->where('role', 'witness')->values()->all();

    signAs($party);
    signAs($w1);
    expect($envelope->fresh()->status->value)->toBe('in_progress');
    Queue::assertNotPushed(SealEnvelope::class);

    signAs($w2);
    expect($envelope->fresh()->status->value)->toBe('sealing');
    Queue::assertPushed(SealEnvelope::class);
});

it('recusa de testemunha recusa o documento', function () {
    $envelope = witnessEnvelope(witnessPeople(1));
    $witness = $envelope->signers->firstWhere('role', 'witness');

    app(SigningService::class)->refuse($witness->fresh(), 'Não conheço as partes');

    expect($envelope->fresh()->status->value)->toBe('refused');
});

it('valida papéis, limite e a presença de ao menos um signatário', function () {
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'ACME']);
    $svc = app(EnvelopeService::class);
    $make = fn (array $people) => $svc->create($tenant, null, "%PDF-1.4\n%%EOF", 'a.pdf', 'C', $people);

    $codes = [];
    foreach ([
        'invalid_role' => [['name' => 'A', 'email' => 'a@ex.com', 'role' => 'approver']],
        'too_many_witnesses' => array_merge([['name' => 'P', 'email' => 'p@ex.com']], array_map(
            fn ($i) => ['name' => "T{$i}", 'email' => "t{$i}@ex.com", 'role' => 'witness'], range(1, 11),
        )),
        'no_signers' => [['name' => 'T', 'email' => 't@ex.com', 'role' => 'witness']],
    ] as $expected => $people) {
        try {
            $make($people);
            $this->fail("deveria falhar com {$expected}");
        } catch (FlowException $e) {
            $codes[$expected] = $e->reason;
        }
    }

    expect($codes)->toBe(['invalid_role' => 'invalid_role', 'too_many_witnesses' => 'too_many_witnesses', 'no_signers' => 'no_signers']);
    expect(Envelope::count())->toBe(0);
});

it('testemunha aparece em Minhas assinaturas quando é a vez', function () {
    $this->post('/cadastro', [
        'company' => 'ACME', 'name' => 'Dono', 'email' => 'dono@acme.com',
        'password' => 'senha-muito-forte', 'password_confirmation' => 'senha-muito-forte',
    ])->assertRedirect('/painel');
    $user = User::where('email', 'dono@acme.com')->firstOrFail();

    Storage::fake('local');
    Queue::fake();
    Mail::fake();
    $envelope = app(EnvelopeService::class)->create($user->tenant, $user, "%PDF-1.4\n%%EOF", 'a.pdf', 'Contrato com testemunha', [
        ['name' => 'Parte', 'email' => 'parte@ex.com', 'role' => 'signer'],
        ['name' => 'Dono', 'email' => 'dono@acme.com', 'role' => 'witness'],
    ]);
    app(EnvelopeService::class)->send($envelope);

    $this->get('/documentos/minhas-assinaturas')->assertOk()
        ->assertSee('Contrato com testemunha')->assertSee('Assinar');
});
