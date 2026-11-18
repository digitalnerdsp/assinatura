<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Envelope;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope as MailEnvelope;

/** Aviso de vencimento de contrato. kind: before (falta pouco) | due (vence hoje ou já venceu). */
final class ContractExpiryMail extends Mailable
{
    public function __construct(public readonly Envelope $contract, public readonly string $kind, public readonly int $daysLeft)
    {
    }

    public function envelope(): MailEnvelope
    {
        $title = $this->contract->title;

        return new MailEnvelope(subject: match (true) {
            $this->kind === 'before' => "Contrato vence em {$this->daysLeft} ".($this->daysLeft === 1 ? 'dia' : 'dias').": {$title}",
            $this->daysLeft === 0 => "Contrato vence hoje: {$title}",
            default => "Contrato venceu: {$title}",
        });
    }

    public function content(): Content
    {
        return new Content(view: 'mail.contract-expiry', with: [
            'brand' => app(\App\Branding\BrandService::class)->for($this->contract->tenant),
            'url' => route('envelopes.show', $this->contract).'#vigencia',
        ]);
    }
}
