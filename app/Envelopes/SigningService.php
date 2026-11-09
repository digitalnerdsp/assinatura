<?php

declare(strict_types=1);

namespace App\Envelopes;

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Enums\SigningOrder;
use App\Envelopes\Exceptions\FlowException;
use App\Evidence\EvidenceLogger;
use App\Evidence\RequestContext;
use App\Jobs\SealEnvelope;
use App\Models\Envelope;
use App\Models\Signer;
use Illuminate\Support\Facades\DB;

/** Fluxo do signatário: abrir link → OTP → assinar/recusar. */
final class SigningService
{
    private const OTP_TTL_MINUTES = 10;
    private const OTP_MAX_ATTEMPTS = 5;

    public function __construct(private readonly EvidenceLogger $evidence)
    {
    }

    public function findByToken(string $token): Signer
    {
        $signer = Signer::query()->where('token_hash', hash('sha256', $token))->first();

        if ($signer === null) {
            throw FlowException::because('invalid_token', 'Link de assinatura inválido.');
        }

        $this->assertOpen($signer);

        return $signer;
    }

    public function view(Signer $signer, ?RequestContext $context = null): void
    {
        $this->assertOpen($signer);

        if ($signer->status === SignerStatus::Pending) {
            $signer->update(['status' => SignerStatus::Viewed, 'viewed_at' => now()]);
        }

        $this->evidence->record($signer->envelope, 'document_viewed', [], $signer, $context);
    }

    /** Gera o OTP e devolve o código em texto puro para o notificador entregar (e-mail/SMS/WhatsApp). */
    public function requestOtp(Signer $signer, string $channel = 'email', ?RequestContext $context = null): string
    {
        $this->assertOpen($signer);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $signer->update([
            'otp_hash' => $this->hashOtp($signer, $code),
            'otp_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
            'otp_attempts' => 0,
            'otp_verified_at' => null,
        ]);

        $this->evidence->record($signer->envelope, 'otp_sent', ['channel' => $channel], $signer, $context);

        return $code;
    }

    public function verifyOtp(Signer $signer, string $code, ?RequestContext $context = null): void
    {
        $this->assertOpen($signer);

        if ($signer->otp_hash === null || $signer->otp_expires_at === null || $signer->otp_expires_at->isPast()) {
            throw FlowException::because('otp_expired', 'Código expirado. Solicite um novo.');
        }

        if ($signer->otp_attempts >= self::OTP_MAX_ATTEMPTS) {
            throw FlowException::because('otp_locked', 'Muitas tentativas. Solicite um novo código.');
        }

        if (! hash_equals($signer->otp_hash, $this->hashOtp($signer, $code))) {
            $signer->increment('otp_attempts');
            $this->evidence->record($signer->envelope, 'otp_failed', ['attempt' => $signer->otp_attempts], $signer, $context);

            throw FlowException::because('otp_invalid', 'Código incorreto.');
        }

        $signer->update(['otp_verified_at' => now(), 'otp_hash' => null]);
        $this->evidence->record($signer->envelope, 'otp_verified', [], $signer, $context);
    }

    /**
     * @param  'drawn'|'typed'  $type
     */
    public function sign(Signer $signer, string $type, string $data, bool $acceptedTerms, ?RequestContext $context = null): void
    {
        $this->assertOpen($signer);

        if (! in_array($type, ['drawn', 'typed'], true) || trim($data) === '') {
            throw FlowException::because('invalid_signature', 'Assinatura inválida.');
        }
        if (! $acceptedTerms) {
            throw FlowException::because('terms_required', 'É necessário aceitar os termos para assinar.');
        }

        $envelope = $signer->envelope;
        $needsOtp = $envelope->required_level !== 'simple';

        if ($needsOtp && $signer->otp_verified_at === null) {
            throw FlowException::because('otp_required', 'Confirme o código de verificação antes de assinar.');
        }

        $this->assertMyTurn($signer, $envelope);

        $allSigned = DB::transaction(function () use ($signer, $envelope, $type, $data, $context) {
            // Trava o signatário para impedir assinatura dupla concorrente.
            $fresh = Signer::query()->whereKey($signer->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->status === SignerStatus::Signed) {
                throw FlowException::because('already_signed', 'Você já assinou este documento.');
            }

            $now = now();
            $level = $signer->otp_verified_at !== null ? 'advanced' : 'simple';

            $fresh->signature()->create([
                'type' => $type,
                'data' => $data,
                'data_sha256' => hash('sha256', $data),
                'document_sha256' => $envelope->document->sha256,
                'level' => $level,
                'signed_at' => $now,
            ]);

            $fresh->update(['status' => SignerStatus::Signed, 'signed_at' => $now]);

            $this->evidence->record($envelope, 'document_signed', [
                'type' => $type,
                'level' => $level,
                'document_sha256' => $envelope->document->sha256,
                'signature_sha256' => hash('sha256', $data),
            ], $fresh, $context);

            $pending = $envelope->signers()->where('role', 'signer')->where('status', '!=', SignerStatus::Signed->value)->count();

            if ($pending === 0) {
                $envelope->update(['status' => EnvelopeStatus::Sealing]);
                $this->evidence->record($envelope, 'all_signed', []);

                return true;
            }

            $envelope->update(['status' => EnvelopeStatus::InProgress]);

            return false;
        });

        if ($allSigned) {
            SealEnvelope::dispatch($envelope->getKey())->afterCommit();
        }
    }

    public function refuse(Signer $signer, string $reason, ?RequestContext $context = null): void
    {
        $this->assertOpen($signer);

        DB::transaction(function () use ($signer, $reason, $context) {
            $signer->update(['status' => SignerStatus::Refused, 'refused_reason' => $reason]);
            $signer->envelope->update(['status' => EnvelopeStatus::Refused]);
            $this->evidence->record($signer->envelope, 'signer_refused', ['reason' => $reason], $signer, $context);
        });
    }

    private function assertOpen(Signer $signer): void
    {
        $envelope = $signer->envelope;

        if ($envelope->expires_at !== null && $envelope->expires_at->isPast() && $envelope->status->isOpenForSigning()) {
            $envelope->update(['status' => EnvelopeStatus::Expired]);
            $this->evidence->record($envelope, 'envelope_expired', []);
        }

        if (! $envelope->status->isOpenForSigning()) {
            throw FlowException::because('envelope_closed', 'Este documento não está mais disponível para assinatura.');
        }

        if (in_array($signer->status, [SignerStatus::Signed, SignerStatus::Refused], true)) {
            throw FlowException::because('signer_done', 'Você já respondeu a este documento.');
        }
    }

    private function assertMyTurn(Signer $signer, Envelope $envelope): void
    {
        if ($envelope->signing_order !== SigningOrder::Sequential) {
            return;
        }

        $blocking = $envelope->signers()
            ->where('role', 'signer')
            ->where('position', '<', $signer->position)
            ->where('status', '!=', SignerStatus::Signed->value)
            ->exists();

        if ($blocking) {
            throw FlowException::because('not_your_turn', 'Aguarde os signatários anteriores assinarem.');
        }
    }

    private function hashOtp(Signer $signer, string $code): string
    {
        return hash_hmac('sha256', $signer->getKey().'|'.$code, (string) config('app.key'));
    }
}
