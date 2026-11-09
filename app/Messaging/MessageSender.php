<?php

declare(strict_types=1);

namespace App\Messaging;

interface MessageSender
{
    /**
     * @param  'sms'|'whatsapp'  $channel
     * @param  string  $to  telefone em formato E.164 (+5511999998888)
     *
     * @throws MessagingException
     */
    public function send(string $channel, string $to, string $text): void;
}
