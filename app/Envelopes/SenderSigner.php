<?php

declare(strict_types=1);

namespace App\Envelopes;

use App\Envelopes\Exceptions\FlowException;
use App\Enums\SignerStatus;
use App\Evidence\RequestContext;
use App\Models\Envelope;

/**
 * Aplica a assinatura do remetente. Ele já está autenticado (sessão ou chave de API), então não passa pelos
 * passos de verificação do envelope (código, selfie, documento, localização) nem tem link público. A prova
 * registrada diz como foi: `method` = session | api_key. O nível da assinatura é sempre "simples".
 */
final class SenderSigner
{
    public function __construct(private readonly SigningService $signing)
    {
    }

    public function apply(Envelope $envelope, SenderSignature $signature, ?RequestContext $context = null): void
    {
        if (! $envelope->status->isOpenForSigning()) {
            throw FlowException::because('envelope_closed', 'Este documento não está mais disponível para assinatura.');
        }

        $sender = $envelope->signers()->where('is_sender', true)->first();
        if ($sender === null) {
            throw FlowException::because('no_sender', 'Este documento não tem remetente para assinar.');
        }
        if ($sender->status === SignerStatus::Signed) {
            throw FlowException::because('signer_done', 'O remetente já assinou este documento.');
        }

        $sender->update(['signed_via' => $signature->method]);

        $this->signing->recordSignature(
            $sender->fresh(),
            $signature->type,
            $signature->data,
            $context,
            array_filter([
                'method' => $signature->method,
                'user_id' => $signature->userId,
                'api_key_id' => $signature->apiKeyId,
            ], fn ($v) => $v !== null),
            'simple',
        );
    }
}
