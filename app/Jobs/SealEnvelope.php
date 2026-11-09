<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EnvelopeStatus;
use App\Evidence\EvidenceLogger;
use App\Models\Envelope;
use App\Signing\Data\SealRequest;
use App\Signing\Exceptions\SigningException;
use App\Signing\SigningManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/** Sela o PDF quando todos assinaram (PAdES + carimbo do tempo) e conclui o envelope. */
final class SealEnvelope implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function __construct(public readonly string $envelopeId)
    {
    }

    public function handle(SigningManager $manager, EvidenceLogger $evidence): void
    {
        $envelope = Envelope::query()->with(['document', 'tenant.signingSetting'])->findOrFail($this->envelopeId);

        if ($envelope->status !== EnvelopeStatus::Sealing) {
            return; // idempotente
        }

        $original = Storage::disk('local')->get($envelope->document->storage_path);

        try {
            $sealed = $manager->seal($envelope->tenant->signingSetting, new SealRequest($original));
        } catch (SigningException $e) {
            $evidence->record($envelope, 'seal_failed', ['error' => $e->getMessage()]);

            throw $e; // a fila tenta novamente
        }

        $path = "tenants/{$envelope->tenant_id}/final/{$envelope->getKey()}.pdf";
        Storage::disk('local')->put($path, $sealed->pdf);

        $envelope->update([
            'status' => EnvelopeStatus::Completed,
            'completed_at' => now(),
            'final_path' => $path,
            'final_sha256' => $sealed->sha256,
            'sealed_provider' => $sealed->provider,
            'sealed_cert_serial' => $sealed->certificate->serial,
            'sealed_at' => $sealed->sealedAt,
        ]);

        $evidence->record($envelope, 'envelope_sealed', [
            'provider' => $sealed->provider,
            'certificate_serial' => $sealed->certificate->serial,
            'icp_brasil' => $sealed->certificate->icpBrasil,
            'timestamped' => $sealed->timestamped,
            'final_sha256' => $sealed->sha256,
        ]);
    }
}
