<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Signer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OtpCodeMail extends Mailable
{
    public function __construct(public readonly Signer $signer, public readonly string $code)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Seu código de verificação');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.otp');
    }
}
