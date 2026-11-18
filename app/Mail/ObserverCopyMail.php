<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Signer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

/** Cópia do documento concluído para quem foi incluído como observador (não assina, não tem link). */
final class ObserverCopyMail extends Mailable
{
    use \App\Mail\Concerns\SealedAttachments;

    /** Link assinado (30 dias) para o observador baixar todos os PDFs selados em zip: ele não tem link de assinatura. */
    public static function zipUrl(Signer $observer): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('observer.copy.zip', now()->addDays(30), ['signer' => $observer->getKey()]);
    }

    public function __construct(public readonly Signer $observer)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Cópia do documento assinado: '.$this->observer->envelope->title);
    }

    public function content(): Content
    {
        $envelope = $this->observer->envelope;

        return new Content(view: 'mail.observer-copy', with: [
            'brand' => app(\App\Branding\BrandService::class)->for($envelope->tenant),
            'verifyUrl' => route('verify.show', $envelope->verification_code),
            'zipUrl' => self::zipUrl($this->observer),
            'partial' => $this->sealedAttachments($envelope)[1],
        ]);
    }

    /** @return list<Attachment> um PDF selado por arquivo do envio, até o teto de tamanho */
    public function attachments(): array
    {
        return $this->sealedAttachments($this->observer->envelope)[0];
    }
}
