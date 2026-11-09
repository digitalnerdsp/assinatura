<?php

declare(strict_types=1);

namespace App\Messaging;

use Illuminate\Support\Facades\Http;
use Throwable;

/** SMS e WhatsApp pela API REST da Twilio. */
final class TwilioSender implements MessageSender
{
    public function send(string $channel, string $to, string $text): void
    {
        $cfg = config('messaging.twilio');
        $from = $channel === 'whatsapp' ? $cfg['from_whatsapp'] : $cfg['from_sms'];

        if (! $cfg['sid'] || ! $cfg['token'] || ! $from) {
            throw new MessagingException("Twilio não configurada para {$channel} (TWILIO_SID, TWILIO_TOKEN e número de origem).");
        }

        $prefix = $channel === 'whatsapp' ? 'whatsapp:' : '';
        $payload = ['To' => $prefix.$to, 'Body' => $text];
        // "MG..." é um Messaging Service; qualquer outro valor é um número.
        $payload[str_starts_with($from, 'MG') ? 'MessagingServiceSid' : 'From'] = $prefix.$from;

        try {
            $response = Http::withBasicAuth($cfg['sid'], $cfg['token'])->asForm()->timeout(15)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$cfg['sid']}/Messages.json", $payload);
        } catch (Throwable $e) {
            throw new MessagingException('Falha de rede ao falar com a Twilio: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new MessagingException('Twilio recusou a mensagem: '.($response->json('message') ?? 'HTTP '.$response->status()));
        }
    }
}
