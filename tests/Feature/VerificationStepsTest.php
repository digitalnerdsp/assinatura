<?php

declare(strict_types=1);

use App\Envelopes\EnvelopeService;
use App\Envelopes\Exceptions\FlowException;
use App\Envelopes\SigningService;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** JPEG real 300x300 gerado em memória. */
function fakeJpeg(int $w = 300, int $h = 300): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 120, 60));
    ob_start();
    imagejpeg($im);
    imagedestroy($im);

    return (string) ob_get_clean();
}

function envelopeWith(array $verifications): array
{
    Storage::fake('local');
    $tenant = Tenant::create(['name' => 'ACME']);
    $envelope = app(EnvelopeService::class)->create(
        $tenant, null, "%PDF-1.4\n%%EOF\n", 'a.pdf', 'Contrato',
        [['name' => 'Ana', 'email' => 'ana@ex.com']],
        verifications: $verifications,
    );
    app(EnvelopeService::class)->send($envelope);

    return [$envelope->fresh(), $envelope->signers()->first()];
}

it('só permite assinar depois de cumprir todas as verificações exigidas', function () {
    Queue::fake();
    [$envelope, $signer] = envelopeWith(['selfie' => true, 'document' => true, 'geolocation' => true]);
    $svc = app(SigningService::class);

    expect($envelope->required_level)->toBe('advanced')
        ->and($svc->pendingSteps($signer))->toBe(['selfie', 'document', 'geolocation']);

    expect(fn () => $svc->sign($signer, 'typed', 'Ana', true))->toThrow(FlowException::class, 'selfie');

    $svc->submitSelfie($signer, fakeJpeg());
    expect($svc->pendingSteps($signer->fresh()))->toBe(['document', 'geolocation']);

    $svc->submitDocument($signer->fresh(), fakeJpeg(600, 400));
    $svc->recordGeolocation($signer->fresh(), -23.55052, -46.633308, 12.5);
    expect($svc->pendingSteps($signer->fresh()))->toBe([]);

    $svc->sign($signer->fresh(), 'drawn', 'data:image/png;base64,AAAA', true);

    $types = $envelope->evidenceEvents()->pluck('type')->all();
    expect($types)->toContain('selfie_captured', 'document_photo_captured', 'geolocation_captured', 'document_signed');
    expect($signer->fresh()->attachments)->toHaveCount(2);
});

it('rejeita arquivos que não são imagem, imagens pequenas e localização inválida', function () {
    [, $signer] = envelopeWith(['selfie' => true, 'geolocation' => true]);
    $svc = app(SigningService::class);

    expect(fn () => $svc->submitSelfie($signer, 'não sou uma imagem'))->toThrow(FlowException::class, 'JPG');
    expect(fn () => $svc->submitSelfie($signer, fakeJpeg(50, 50)))->toThrow(FlowException::class, 'resolução');
    expect(fn () => $svc->recordGeolocation($signer, 200.0, 0.0, null))->toThrow(FlowException::class, 'inválida');
});

it('selfie grava o hash da imagem na trilha de evidências', function () {
    [$envelope, $signer] = envelopeWith(['selfie' => true]);
    $jpeg = fakeJpeg();
    app(SigningService::class)->submitSelfie($signer, $jpeg, 'camera');

    $event = $envelope->evidenceEvents()->where('type', 'selfie_captured')->firstOrFail();
    expect($event->payload['sha256'])->toBe(hash('sha256', $jpeg))
        ->and($event->payload['source'])->toBe('camera');
    expect(Storage::disk('local')->allFiles("tenants/{$envelope->tenant_id}/signers"))->toHaveCount(1);
});

it('envelope sem verificações segue como assinatura simples', function () {
    [$envelope, $signer] = envelopeWith([]);

    expect($envelope->required_level)->toBe('simple')
        ->and(app(SigningService::class)->pendingSteps($signer))->toBe([]);
});
