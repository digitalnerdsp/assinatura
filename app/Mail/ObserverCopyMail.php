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
        ]);
    }

    /** @return list<Attachment> um PDF selado por arquivo do envio */
    public function attachments(): array
    {
        $out = [];
        foreach ($this->observer->envelope->files() as $file) {
            if ($file->finalPath !== null && Storage::disk('local')->exists($file->finalPath)) {
                $out[] = Attachment::fromStorageDisk('local', $file->finalPath)->as('assinado-'.$file->name)->withMime('application/pdf');
            }
        }

        return $out;
    }
}
