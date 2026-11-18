<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Mail\ContractExpiryMail;
use App\Models\ContractAlert;
use App\Models\Envelope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisos por e-mail de vencimento de contrato. Roda uma vez por dia (08:00 de Brasília).
 * "before": entrou na janela escolhida (dias antes). "due": chegou ou passou o dia do vencimento.
 * Cada aviso sai uma vez por data de vencimento e só para os e-mails indicados no contrato.
 */
final class ContractAlerts
{
    public function __construct(private readonly ContractWatch $watch)
    {
    }

    /** @return int quantos avisos (contrato + tipo) foram enviados */
    public function send(): int
    {
        $today = $this->watch->today();
        $sent = 0;

        Envelope::query()
            ->where('status', \App\Enums\EnvelopeStatus::Completed->value)
            ->whereNull('trashed_at')
            ->whereNotNull('contract_expires_on')
            ->whereRaw('contract_expires_on <= cast(? as date) + contract_alert_days', [$today->toDateString()])
            ->with(['tenant', 'contractAlerts'])
            ->orderBy('contract_expires_on')
            ->orderBy('id')
            ->each(function (Envelope $envelope) use ($today, &$sent): void {
                if ($envelope->tenant === null || $envelope->tenant->isSuspended()) {
                    return;
                }

                $left = (int) $this->watch->daysLeft($envelope, $today);
                $kind = $left <= 0 ? 'due' : 'before';
                $due = $envelope->contract_expires_on->toDateString();

                if ($envelope->contractAlerts->contains(fn (ContractAlert $a) => $a->kind === $kind && $a->due_on->toDateString() === $due)) {
                    return;
                }

                $emails = array_values(array_filter((array) $envelope->contract_alert_emails));
                if ($emails === []) {
                    return;
                }

                // Reserva o registro antes de enviar: duas execuções ao mesmo tempo não mandam o aviso em dobro.
                $reserved = ContractAlert::query()->insertOrIgnore([['id' => (string) \Illuminate\Support\Str::uuid7(), 'envelope_id' => $envelope->getKey(), 'kind' => $kind, 'due_on' => $due, 'sent_to' => '', 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]]);
                if ($reserved === 0) {
                    return;
                }

                $delivered = [];
                foreach ($emails as $to) {
                    try {
                        Mail::to($to)->send(new ContractExpiryMail($envelope, $kind, $left));
                        $delivered[] = $to;
                    } catch (Throwable $e) {
                        Log::warning('Aviso de vencimento de contrato não enviado', ['envelope' => $envelope->getKey(), 'erro' => $e->getMessage()]);
                    }
                }

                $record = ContractAlert::query()->where('envelope_id', $envelope->getKey())->where('kind', $kind)->where('due_on', $due);
                if ($delivered === []) {
                    $record->delete(); // ninguém recebeu: tenta de novo amanhã
                    return;
                }
                $record->update(['sent_to' => implode(', ', $delivered)]);
                $sent++;
            });

        return $sent;
    }
}
