<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\OtpCodeMail;
use App\Mail\SignatureRequestedMail;
use App\Models\Signer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Entrega links e códigos aos signatários. Hoje só e-mail; SMS/WhatsApp entram aqui. */
final class SignerNotifier
{
    /** Retorna false (sem lançar) se o e-mail falhar: o link continua disponível para envio manual. */
    public function invite(Signer $signer, string $token): bool
    {
        return $this->safely(fn () => Mail::to($signer->email, $signer->name)->send(
            new SignatureRequestedMail($signer->loadMissing('envelope'), route('sign.show', ['token' => $token]))
        ), 'convite', $signer);
    }

    public function otp(Signer $signer, string $code): bool
    {
        return $this->safely(fn () => Mail::to($signer->email, $signer->name)->send(
            new OtpCodeMail($signer->loadMissing('envelope'), $code)
        ), 'otp', $signer);
    }

    private function safely(callable $send, string $what, Signer $signer): bool
    {
        try {
            $send();

            return true;
        } catch (Throwable $e) {
            Log::error("Falha ao enviar e-mail ({$what}) ao signatário {$signer->getKey()}: ".$e->getMessage());

            return false;
        }
    }
}
