<?php

declare(strict_types=1);

namespace App\Envelopes;

use App\Envelopes\Exceptions\FlowException;
use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use Illuminate\Support\Facades\DB;
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

    /** Apaga de vez o que está na lixeira há `$olderThanDays` dias ou mais. Devolve quantos envelopes removeu. */
    public function purge(int $olderThanDays = 30): int
    {
        $removed = 0;

        Envelope::query()
            ->whereNotNull('trashed_at')
            ->where('trashed_at', '<=', now()->subDays($olderThanDays))
            ->whereIn('status', [EnvelopeStatus::Draft->value, EnvelopeStatus::Cancelled->value, EnvelopeStatus::Expired->value])
            ->with(['document', 'signers.attachments'])
            ->each(function (Envelope $envelope) use (&$removed): void {
                $disk = Storage::disk('local');
                $document = $envelope->document;

                DB::transaction(function () use ($envelope, $document): void {
                    $envelope->delete(); // signatários, anexos e evidências caem por cascata
                    $document?->delete(); // documents é restrito a partir do envelope: apaga depois
                });

                // Arquivos só depois do banco confirmar (um erro antes deixaria registro sem arquivo).
                foreach ($envelope->signers as $signer) {
                    foreach ($signer->attachments as $attachment) {
                        $disk->delete($attachment->path);
                    }
                }
                if ($document) {
                    $disk->delete($document->storage_path);
                }

                $removed++;
            });

        return $removed;
    }
}
