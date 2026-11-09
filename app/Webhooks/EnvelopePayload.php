<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Models\Envelope;
use App\Models\Signer;

/** Corpo enviado aos webhooks. Nunca inclui links de assinatura, CPF ou imagens. */
final class EnvelopePayload
{
    /** @return array<string,mixed> */
    public static function make(Envelope $envelope, ?Signer $signer = null): array
    {
        $envelope->unsetRelation('signers');
        $envelope->load('signers');

        $data = [
            'envelope' => [
                'id' => $envelope->getKey(),
                'title' => $envelope->title,
                'status' => $envelope->status->value,
                'verification_code' => $envelope->verification_code,
                'verification_url' => route('verify.show', $envelope->verification_code),
                'sent_at' => $envelope->sent_at?->toIso8601String(),
                'expires_at' => $envelope->expires_at?->toIso8601String(),
                'completed_at' => $envelope->completed_at?->toIso8601String(),
                'signers' => $envelope->signers->map(fn (Signer $s) => [
                    'id' => $s->getKey(),
                    'name' => $s->name,
                    'email' => $s->email,
                    'status' => $s->status->value,
                    'signed_at' => $s->signed_at?->toIso8601String(),
                ])->all(),
            ],
        ];

        if ($signer !== null) {
            $data['signer'] = [
                'id' => $signer->getKey(),
                'name' => $signer->name,
                'email' => $signer->email,
                'status' => $signer->status->value,
                'signed_at' => $signer->signed_at?->toIso8601String(),
                'refused_reason' => $signer->refused_reason,
            ];
        }

        return $data;
    }
}
