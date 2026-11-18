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
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $path = $this->document->final_path;
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return [];
        }

        return [Attachment::fromStorageDisk('local', $path)->as('assinado-'.$this->document->document->name)->withMime('application/pdf')];
    }
}
