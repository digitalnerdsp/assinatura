<?php

declare(strict_types=1);

namespace App\Assistant\Notifications;

use App\Assistant\AssistantSettings;
use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use Carbon\CarbonImmutable;

/** Avisa quem criou o documento que está esperando assinatura há X dias sem nenhuma movimentação. Um aviso por parada. */
final class StalledDocuments
{
    public function __construct(private readonly AssistantSettings $settings, private readonly WhatsAppNotifier $notifier)
    {
    }

    /** @return int quantos avisos foram enfileirados */
    public function run(): int
    {
        $days = $this->settings->stalledDays();
        $limit = CarbonImmutable::now()->subDays($days);
        $sent = 0;

        Envelope::query()
            ->whereIn('status', [EnvelopeStatus::Sent->value, EnvelopeStatus::InProgress->value])
            ->whereNull('trashed_at')->whereNotNull('sent_at')->whereNotNull('created_by')
            ->where('sent_at', '<=', $limit)
            ->withMax('signers as last_signed_at', 'signed_at')
            ->orderBy('sent_at')
            ->each(function (Envelope $e) use ($limit, &$sent): void {
                $last = CarbonImmutable::parse($e->last_signed_at ?? $e->sent_at);
                $last = $e->sent_at->greaterThan($last) ? CarbonImmutable::instance($e->sent_at) : $last;
                if ($last->greaterThan($limit)) {
                    return;
                }
                $stalled = (int) $last->diffInDays(CarbonImmutable::now());

                if ($this->notifier->toCreator($e, 'document_stalled', [$e->title, (string) $stalled], 'stalled:'.$e->getKey().':'.$last->format('Ymd')) === 'sent_queued') {
                    $sent++;
                }
            });

        return $sent;
    }
}
