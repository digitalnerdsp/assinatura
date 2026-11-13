<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class PlanUsageWarningMail extends Mailable
{
    /** @param list<array{key:string,label:string,used:int,limit:int,pct:int}> $items */
    public function __construct(public readonly Tenant $tenant, public readonly array $items, public readonly bool $full)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->full ? 'Você chegou ao limite do seu plano' : 'Você está perto do limite do seu plano');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.plan-usage-warning');
    }
}
