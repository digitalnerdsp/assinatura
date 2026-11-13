<?php

declare(strict_types=1);

namespace App\Envelopes;

use App\Envelopes\Exceptions\FlowException;
use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Lixeira (30 dias, só rascunho/cancelado/expirado) e arquivo (concluído/recusado) de envelopes. */
final class TrashService
{
    public function trash(Envelope $envelope): void
    {
        if (! $envelope->isTrashable()) {
            throw FlowException::because('not_trashable', 'Só rascunhos, cancelados e expirados podem ir para a lixeira.');
        }

        $envelope->forceFill(['trashed_at' => now()])->save();
    }

    public function restore(Envelope $envelope): void
    {
        if (! $envelope->isTrashed()) {
            throw FlowException::because('not_trashed', 'Este documento não está na lixeira.');
        }

        $envelope->forceFill(['trashed_at' => null])->save();
    }

    public function archive(Envelope $envelope): void
    {
        if (! $envelope->isArchivable()) {
            throw FlowException::because('not_archivable', 'Só documentos concluídos ou recusados podem ser arquivados.');
        }

        $envelope->forceFill(['archived_at' => now()])->save();
    }

    public function unarchive(Envelope $envelope): void
    {
        if (! $envelope->isArchived()) {
            throw FlowException::because('not_archived', 'Este documento não está arquivado.');
        }

        $envelope->forceFill(['archived_at' => null])->save();
    }

    /**
     * Apaga de vez o que está na lixeira há `$olderThanDays` dias ou mais. Devolve quantos envelopes removeu.
     * Cada envelope é independente: se um falhar (o erro vai para o log), os demais seguem.
     */
    public function purge(int $olderThanDays = 30): int
    {
        $removed = 0;

        Envelope::query()
            ->whereNotNull('trashed_at')
            ->where('trashed_at', '<=', now()->subDays($olderThanDays))
            ->whereIn('status', [EnvelopeStatus::Draft->value, EnvelopeStatus::Cancelled->value, EnvelopeStatus::Expired->value])
            ->with(['document', 'signers.attachments'])
            ->eachById(function (Envelope $envelope) use (&$removed): void {
                try {
                    $this->purgeOne($envelope);
                    $removed++;
                } catch (\Throwable $e) {
                    Log::error("Limpeza da lixeira: falha ao apagar o envelope {$envelope->getKey()}: ".$e->getMessage());
                }
            }, 100);

        return $removed;
    }

    private function purgeOne(Envelope $envelope): void
    {
        $document = $envelope->document;
        $tenantId = $envelope->tenant_id;
        $signers = $envelope->signers;
        $freed = (int) $document?->size + (int) $signers->flatMap->attachments->sum('size');
        $tenant = $envelope->tenant;

        DB::transaction(function () use ($envelope, $document): void {
            // A trilha de evidências é append-only; só esta limpeza pode apagá-la (ver a migration do gatilho).
            $pgsql = DB::getDriverName() === 'pgsql';
            if ($pgsql) {
                DB::statement("SET LOCAL app.evidence_purge = 'on'");
            }

            try {
                $envelope->delete(); // signatários, anexos e evidências caem por cascata
                $document?->delete(); // documents é restrito a partir do envelope: apaga depois
            } finally {
                if ($pgsql) {
                    DB::statement("SET LOCAL app.evidence_purge = 'off'");
                }
            }
        });

        // Arquivos só depois do banco confirmar. Apaga a pasta inteira de cada signatário (inclui selfies
        // antigas que um novo envio deixou para trás) e cada caminho registrado, avisando se algo ficar.
        $disk = Storage::disk('local');
        foreach ($signers as $signer) {
            $this->warnIfFailed($disk->deleteDirectory("tenants/{$tenantId}/signers/{$signer->getKey()}"), "pasta do signatário {$signer->getKey()}");
            foreach ($signer->attachments as $attachment) {
                if ($disk->exists($attachment->path)) {
                    $this->warnIfFailed($disk->delete($attachment->path), $attachment->path);
                }
            }
        }
        if ($document && $disk->exists($document->storage_path)) {
            $this->warnIfFailed($disk->delete($document->storage_path), $document->storage_path);
        }
        app(\App\Plans\StorageUsage::class)->remove($tenant, $freed);
    }

    private function warnIfFailed(bool $ok, string $what): void
    {
        if (! $ok) {
            Log::warning("Limpeza da lixeira: não foi possível apagar do disco: {$what}");
        }
    }
}
