<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EnvelopeStatus;
use App\Envelopes\TrashService;
use App\Models\Envelope;
use Illuminate\Console\Command;

/**
 * Rascunhos do assistente que ninguém terminou: depois de N dias sem alteração vão para a lixeira (que apaga de vez em
 * 30 dias e devolve o armazenamento). Rascunhos criados pela API ou por modelo (sem `wizard_step`) não entram.
 */
final class TrashStaleDrafts extends Command
{
    protected $signature = 'documents:trash-stale-drafts {--days=14 : dias sem alteração}';

    protected $description = 'Move para a lixeira os rascunhos do assistente parados há muito tempo';

    public function handle(TrashService $trash): int
    {
        $days = max(1, (int) $this->option('days'));
        $moved = 0;

        Envelope::query()
            ->where('status', EnvelopeStatus::Draft->value)
            ->whereNotNull('wizard_step')
            ->whereNull('trashed_at')
            ->where('updated_at', '<', now()->subDays($days))
            ->eachById(function (Envelope $envelope) use ($trash, &$moved): void {
                $trash->trash($envelope);
                $moved++;
            });

        $this->info("{$moved} documento(s) movido(s) para a lixeira.");

        return self::SUCCESS;
    }
}
