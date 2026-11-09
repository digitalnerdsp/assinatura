<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Signer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class SignatureRequestedMail extends Mailable
{
    public function __construct(public readonly Signer $signer, public readonly string $url, public readonly bool $reminder = false)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->reminder ? 'Lembrete: ' : '').'Documento para assinar: '.$this->signer->envelope->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.signature-requested');
    }
}
