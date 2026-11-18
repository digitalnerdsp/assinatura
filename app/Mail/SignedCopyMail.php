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

    /** @return list<Attachment> um PDF selado por arquivo do envio */
    public function attachments(): array
    {
        $out = [];
        foreach ($this->document->files() as $file) {
            if ($file->finalPath !== null && Storage::disk('local')->exists($file->finalPath)) {
                $out[] = Attachment::fromStorageDisk('local', $file->finalPath)->as('assinado-'.$file->name)->withMime('application/pdf');
            }
        }

        return $out;
    }
}
