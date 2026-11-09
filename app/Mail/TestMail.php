<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Teste de e-mail — '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Se você recebeu esta mensagem, o envio de e-mail da plataforma está funcionando.</p>');
    }
}
