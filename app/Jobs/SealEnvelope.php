<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EnvelopeStatus;
use App\Evidence\EvidenceLogger;
use App\Models\Envelope;
use App\Signing\Data\SealRequest;
use App\Signing\ReportBuilder;
use App\Signing\Exceptions\SigningException;
use App\Signing\SigningManager;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/** Sela o PDF quando todos assinaram (PAdES + carimbo do tempo) e conclui o documento. */
final class SealEnvelope implements ShouldQueue
{
    /** Manda o PDF selado a cada observador. Falha de e-mail nunca desfaz a conclusão: fica no log e a trilha só registra o que foi enviado. */
    private function sendObserverCopies(Envelope $envelope, EvidenceLogger $evidence): void
    {
        foreach ($envelope->signers()->where('role', 'observer')->get() as $observer) {
            try {
                \Illuminate\Support\Facades\Mail::to($observer->email)->send(new \App\Mail\ObserverCopyMail($observer));
                $evidence->record($envelope, 'observer_copy_sent', ['observer' => $observer->name], $observer);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** Quem assinou e o remetente também ficam com uma via. Falha de e-mail nunca desfaz a conclusão. */
    private function sendSignedCopies(Envelope $envelope, EvidenceLogger $evidence): void
    {
        $sent = [];
        foreach ($envelope->signers()->whereIn('role', Envelope::PARTICIPANT_ROLES)->get() as $signer) {
            $this->sendCopy($envelope, $evidence, $signer->email, $signer->name, $signer->signUrl(), $signer, $sent);
        }

        $sender = $envelope->created_by !== null ? \App\Models\User::query()->find($envelope->created_by) : null;
        if ($sender !== null) {
            $this->sendCopy($envelope, $evidence, $sender->email, $sender->name, route('envelopes.show', $envelope), null, $sent);
        }
    }

    /** @param list<string> $sent */
    private function sendCopy(Envelope $envelope, EvidenceLogger $evidence, ?string $email, string $name, ?string $link, ?\App\Models\Signer $signer, array &$sent): void
    {
        if ($email === null || $email === '' || in_array(strtolower($email), $sent, true)) {
            return;
        }
        $sent[] = strtolower($email);

        try {
            \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\SignedCopyMail($envelope, $name, $link));
            $evidence->record($envelope, 'signed_copy_sent', ['to' => $name], $signer);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Sela um arquivo do envio e grava o resultado no próprio arquivo. SigningException deixa o envio em "sealing" para nova tentativa. */
    private function sealFile(Envelope $envelope, \App\Envelopes\DocumentFile $file, bool $many, SigningManager $manager, EvidenceLogger $evidence, ReportBuilder $reports): void
    {
        $original = Storage::disk('local')->get($file->storagePath);

        try {
            $sealed = $manager->seal(
                $envelope->tenant->signingSetting,
                new SealRequest($original, report: $reports->build($envelope, $file)),
            );
        } catch (SigningException $e) {
            $evidence->record($envelope, 'seal_failed', ['error' => $e->getMessage()] + ($many ? ['document' => $file->name] : []));

            throw $e; // a fila tenta novamente
        }

        $path = "tenants/{$envelope->tenant_id}/final/{$envelope->getKey()}".($file->extraId === null ? '' : "-{$file->n}").'.pdf';
        Storage::disk('local')->put($path, $sealed->pdf);
        $previousFinal = (int) $file->finalSize;

        if ($file->extraId === null) {
            $envelope->update([
                'final_size' => strlen($sealed->pdf),
                'final_path' => $path,
                'final_sha256' => $sealed->sha256,
                'sealed_provider' => $sealed->provider,
                'sealed_cert_serial' => $sealed->certificate->serial,
                'sealed_at' => $sealed->sealedAt,
            ]);
        } else {
            \App\Models\EnvelopeDocument::query()->whereKey($file->extraId)->update([
                'final_path' => $path, 'final_sha256' => $sealed->sha256, 'final_size' => strlen($sealed->pdf), 'sealed_at' => $sealed->sealedAt,
            ]);
        }

        try {
            app(\App\Plans\StorageUsage::class)->add($envelope->tenant, strlen($sealed->pdf) - $previousFinal);
        } catch (\Throwable $e) {
            report($e);
        }

        $evidence->record($envelope, 'document_sealed', [
            'provider' => $sealed->provider,
            'certificate_serial' => $sealed->certificate->serial,
            'icp_brasil' => $sealed->certificate->icpBrasil,
            'timestamped' => $sealed->timestamped,
            'report_attached' => $sealed->decorated,
            'final_sha256' => $sealed->sha256,
        ] + ($many ? ['document' => $file->name, 'document_n' => $file->n] : []));
    }

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

    public function handle(SigningManager $manager, EvidenceLogger $evidence, ?ReportBuilder $reports = null, ?WebhookDispatcher $webhooks = null): void
    {
        $reports ??= new ReportBuilder();
        $webhooks ??= app(WebhookDispatcher::class);
        $envelope = Envelope::query()->with(['document', 'tenant.signingSetting'])->findOrFail($this->envelopeId);

        if ($envelope->status !== EnvelopeStatus::Sealing) {
            return; // idempotente
        }

        // Um PDF selado por arquivo. O que já foi selado numa tentativa anterior não é refeito.
        $files = $envelope->files();
        foreach ($files as $file) {
            if ($file->finalPath !== null && Storage::disk('local')->exists($file->finalPath)) {
                continue;
            }
            $this->sealFile($envelope, $file, $files->count() > 1, $manager, $evidence, $reports);
        }

        $envelope->update(['status' => EnvelopeStatus::Completed, 'completed_at' => now()]);

        try {
            app(\App\Plans\UsageLedger::class)->charge($envelope, 'complete'); // um envio conta uma vez, mesmo com vários arquivos; nunca impede a conclusão
            app(\App\Plans\PlanUsageMonitor::class)->check($envelope->tenant);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->sendSignedCopies($envelope->fresh(), $evidence);
        $this->sendObserverCopies($envelope->fresh(), $evidence);

        $webhooks->dispatch($envelope->fresh(), 'document.completed');
    }
}
