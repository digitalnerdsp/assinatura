<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SigningService;
use App\Evidence\EvidenceLogger;
use App\Jobs\SealEnvelope;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Tenant;
use App\Signing\Contracts\SigningProvider;
use App\Signing\Data\CertificateInfo;
use App\Signing\Data\SealedDocument;
use App\Signing\Data\SealRequest;
use App\Signing\SigningManager;
use App\Signing\Support\SealerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

/** @return array{0:Envelope,1:array<string,string>} envelope enviado + tokens (signer_id => token) */
function sentEnvelope(SigningOrder $order = SigningOrder::Parallel, string $level = 'simple', int $signers = 2): array
{
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'ACME']);

    $envelope = app(EnvelopeService::class)->create(
        $tenant,
        null,
        "%PDF-1.4\n%teste\n",
        'contrato.pdf',
        'Contrato',
        array_map(fn ($i) => ['name' => "Signer {$i}", 'email' => "s{$i}@example.com"], range(1, $signers)),
        $order,
        $level,
    );

    return [$envelope, app(EnvelopeService::class)->send($envelope)];
}

function signerOf(Envelope $envelope, int $position): Signer
{
    return $envelope->signers()->where('position', $position)->firstOrFail();
}

it('conclui o envelope quando todos assinam e despacha a selagem', function () {
    Queue::fake();
    [$envelope] = sentEnvelope();
    $svc = app(SigningService::class);

    $svc->sign(signerOf($envelope, 1), 'typed', 'Signer 1', true);
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::InProgress);
    Queue::assertNothingPushed();

    $svc->sign(signerOf($envelope, 2), 'typed', 'Signer 2', true);
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Sealing);
    Queue::assertPushed(SealEnvelope::class);
});

it('respeita a ordem sequencial de assinatura', function () {
    Queue::fake();
    [$envelope] = sentEnvelope(SigningOrder::Sequential);

    expect(fn () => app(SigningService::class)->sign(signerOf($envelope, 2), 'typed', 'Signer 2', true))
        ->toThrow(FlowException::class, 'Aguarde');

    app(SigningService::class)->sign(signerOf($envelope, 1), 'typed', 'Signer 1', true);
    app(SigningService::class)->sign(signerOf($envelope, 2), 'typed', 'Signer 2', true);

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Sealing);
});

it('exige aceite dos termos e impede assinar duas vezes', function () {
    Queue::fake();
    [$envelope] = sentEnvelope();
    $svc = app(SigningService::class);

    expect(fn () => $svc->sign(signerOf($envelope, 1), 'typed', 'Signer 1', false))->toThrow(FlowException::class);

    $svc->sign(signerOf($envelope, 1), 'typed', 'Signer 1', true);
    expect(fn () => $svc->sign(signerOf($envelope, 1), 'typed', 'Signer 1', true))->toThrow(FlowException::class);
});

it('encontra o signatário pelo token e rejeita token inválido', function () {
    [$envelope, $tokens] = sentEnvelope();
    $signer = signerOf($envelope, 1);

    expect(app(SigningService::class)->findByToken($tokens[$signer->id])->id)->toBe($signer->id);
    expect(fn () => app(SigningService::class)->findByToken('token-invalido'))->toThrow(FlowException::class);
    // somente o hash é guardado
    expect($signer->fresh()->token_hash)->toBe(hash('sha256', $tokens[$signer->id]));
});

it('nível avançado exige OTP; código errado falha e o correto libera', function () {
    Queue::fake();
    [$envelope] = sentEnvelope(level: 'advanced', signers: 1);
    $svc = app(SigningService::class);
    $signer = signerOf($envelope, 1);

    expect(fn () => $svc->sign($signer, 'typed', 'Signer 1', true))->toThrow(FlowException::class, 'código');

    $code = $svc->requestOtp($signer);
    $wrong = $code === '000000' ? '111111' : '000000';

    expect(fn () => $svc->verifyOtp($signer->fresh(), $wrong))->toThrow(FlowException::class, 'incorreto');

    $svc->verifyOtp($signer->fresh(), $code);
    $svc->sign($signer->fresh(), 'typed', 'Signer 1', true);

    expect($signer->fresh()->signature->level)->toBe('advanced');
});

it('bloqueia o OTP após 5 tentativas erradas', function () {
    [$envelope] = sentEnvelope(level: 'advanced', signers: 1);
    $svc = app(SigningService::class);
    $signer = signerOf($envelope, 1);

    $code = $svc->requestOtp($signer);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $_) {
        try {
            $svc->verifyOtp($signer->fresh(), $wrong);
        } catch (FlowException) {
        }
    }

    // mesmo com o código certo, fica travado até pedir outro
    expect(fn () => $svc->verifyOtp($signer->fresh(), $code))->toThrow(FlowException::class, 'tentativas');
});

it('recusa assinatura em envelope expirado', function () {
    [$envelope] = sentEnvelope();
    $envelope->update(['expires_at' => now()->subMinute()]);

    expect(fn () => app(SigningService::class)->sign(signerOf($envelope, 1)->fresh(), 'typed', 'x', true))
        ->toThrow(FlowException::class);
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Expired);
});

it('a selagem grava o PDF final, conclui o envelope e mantém a cadeia íntegra', function () {
    Queue::fake();
    [$envelope] = sentEnvelope(signers: 1);
    app(SigningService::class)->sign(signerOf($envelope, 1), 'drawn', 'data:image/png;base64,AAAA', true);

    $provider = new class implements SigningProvider {
        public function name(): string { return 'fake'; }
        public function certificate(): CertificateInfo
        {
            return new CertificateInfo('SERIAL-1', 'AC Teste', 'CN=ACME', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 year'), false);
        }
        public function isAvailable(): bool { return true; }
        public function seal(SealRequest $request): SealedDocument
        {
            $pdf = $request->pdf."\n%sealed";

            return new SealedDocument($pdf, hash('sha256', $pdf), 'fake', $this->certificate(), new DateTimeImmutable(), true);
        }
    };

    $manager = new SigningManager(
        new SealerClient(new Http(), 'http://sealer', 't'),
        new NullLogger(),
        ['platform_provider' => 'fake', 'allow_local_test' => false],
        fn () => $provider,
    );

    (new SealEnvelope($envelope->id))->handle($manager, app(EvidenceLogger::class));

    $envelope->refresh();
    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->sealed_provider)->toBe('fake')
        ->and(Storage::disk('local')->exists($envelope->final_path))->toBeTrue()
        ->and(hash('sha256', Storage::disk('local')->get($envelope->final_path)))->toBe($envelope->final_sha256)
        ->and(app(EvidenceLogger::class)->isIntact($envelope))->toBeTrue()
        ->and($envelope->evidenceEvents()->pluck('type')->all())
        ->toContain('envelope_created', 'envelope_sent', 'document_signed', 'all_signed', 'envelope_sealed');
});
