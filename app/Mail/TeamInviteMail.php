<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class TeamInviteMail extends Mailable
{
    public function __construct(public readonly User $member, public readonly User $owner, public readonly string $url)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Convite para acessar '.$this->owner->tenant->name);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.team-invite', text: 'mail.team-invite-text');
    }
}
