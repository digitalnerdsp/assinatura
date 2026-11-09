<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\OtpCodeMail;
use App\Mail\SignatureRequestedMail;
use App\Models\Signer;
use Illuminate\Support\Facades\Mail;

/** Entrega links e códigos aos signatários. Hoje só e-mail; SMS/WhatsApp entram aqui. */
final class SignerNotifier
{
    public function invite(Signer $signer, string $token): void
    {
        Mail::to($signer->email, $signer->name)->send(
            new SignatureRequestedMail($signer->loadMissing('envelope'), route('sign.show', ['token' => $token]))
        );
    }

    public function otp(Signer $signer, string $code): void
    {
        Mail::to($signer->email, $signer->name)->send(new OtpCodeMail($signer->loadMissing('envelope'), $code));
    }
}
