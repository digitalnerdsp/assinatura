<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Envelope as EnvelopeModel;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

/** Cópia do documento concluído para quem assinou e para o remetente: o PDF selado vai em anexo e o link permite baixar de novo. */
final class SignedCopyMail extends Mailable
{
    use \App\Mail\Concerns\SealedAttachments;

    public function __construct(
        public readonly EnvelopeModel $document,
        public readonly string $recipientName,
        public readonly ?string $link = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Documento assinado: '.$this->document->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.signed-copy', with: [
            'brand' => app(\App\Branding\BrandService::class)->for($this->document->tenant),
            'verifyUrl' => route('verify.show', $this->document->verification_code),
            'partial' => $this->sealedAttachments($this->document)[1],
        ]);
    }

    /** @return list<Attachment> um PDF selado por arquivo do envio, até o teto de tamanho */
    public function attachments(): array
    {
        return $this->sealedAttachments($this->document)[0];
    }
}
