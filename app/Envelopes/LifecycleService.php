<?php

declare(strict_types=1);

namespace App\Envelopes;

use App\Enums\EnvelopeStatus;
use App\Evidence\EvidenceLogger;
use App\Models\Envelope;
use App\Notifications\SignerNotifier;
use App\Webhooks\WebhookDispatcher;

/** Rotinas agendadas: expiração de envelopes e lembretes para quem ainda não assinou. */
final class LifecycleService
{
    public function __construct(
        private readonly EvidenceLogger $evidence,
        private readonly WebhookDispatcher $webhooks,
        private readonly SignerNotifier $notifier,
    ) {
    }

    /** Marca o envelope como expirado se o prazo passou. Retorna true se mudou. */
    public function expire(Envelope $envelope): bool
    {
        if ($envelope->expires_at === null || ! $envelope->expires_at->isPast() || ! $envelope->status->isOpenForSigning()) {
            return false;
        }

        $envelope->update(['status' => EnvelopeStatus::Expired]);
        $this->evidence->record($envelope, 'envelope_expired', []);
        app(\App\Plans\UsageLedger::class)->refund($envelope);
        $this->webhooks->dispatch($envelope, 'envelope.expired');

        return true;
    }

    /** @return int quantidade de envelopes expirados */
    public function expireDue(): int
    {
        $count = 0;

        Envelope::query()
            ->whereIn('status', [EnvelopeStatus::Sent->value, EnvelopeStatus::InProgress->value])
            ->where('expires_at', '<', now())
            ->each(function (Envelope $envelope) use (&$count) {
                $count += $this->expire($envelope) ? 1 : 0;
            });

        return $count;
    }

    /** @return int quantidade de lembretes enviados */
    public function sendReminders(): int
    {
        $sent = 0;
        $max = (int) config('signing.max_reminders');

        Envelope::query()
            ->whereIn('status', [EnvelopeStatus::Sent->value, EnvelopeStatus::InProgress->value])
            ->whereNotNull('reminder_every_days')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with(['tenant'])
            ->each(function (Envelope $envelope) use (&$sent, $max) {
                if ($envelope->tenant->isSuspended()) {
                    return;
                }

                foreach ($envelope->activeSigners() as $signer) {
                    $reference = $signer->last_reminded_at ?? $signer->invited_at;

                    if ($reference === null || $signer->reminders_count >= $max
                        || $reference->gt(now()->subDays($envelope->reminder_every_days))) {
                        continue;
                    }

                    $url = $signer->signUrl();
                    if ($url === null || ! $this->notifier->invite($signer, basename($url), reminder: true)) {
                        continue;
                    }

                    $signer->update(['last_reminded_at' => now(), 'reminders_count' => $signer->reminders_count + 1]);
                    $this->evidence->record($envelope, 'reminder_sent', ['number' => $signer->reminders_count], $signer);
                    $sent++;
                }
            });

        return $sent;
    }
}
