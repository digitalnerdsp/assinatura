<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\OtpCodeMail;
use App\Mail\SignatureRequestedMail;
use App\Messaging\MessageSender;
use App\Messaging\MessagingException;
use App\Messaging\Phone;
use App\Models\Envelope;
use App\Models\Signer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Entrega links e códigos aos signatários, pelos canais escolhidos no envelope (e-mail, WhatsApp, SMS). */
final class SignerNotifier
{
    public function __construct(private readonly MessageSender $messages)
    {
    }

    /**
     * Convida o signatário pelos canais do envelope. Retorna true se ao menos um canal entregou;
     * nunca lança: o link continua disponível para envio manual.
     */
    public function invite(Signer $signer, string $token, bool $reminder = false): bool
    {
        $signer->loadMissing('envelope');
        if ($this->isManual($signer->envelope)) {
            return false; // o cliente escolheu enviar os links manualmente
        }

        $url = route('sign.show', ['token' => $token]);
        $delivered = false;

        foreach ($signer->envelope->channels() as $channel) {
            $delivered = match ($channel) {
                'email' => $this->safely(fn () => Mail::to($signer->email, $signer->name)->send(new SignatureRequestedMail($signer, $url, $reminder)), 'convite', $signer),
                default => $this->viaPhone($signer, $channel, $this->text($signer, $url, $reminder)),
            } || $delivered;
        }

        return $delivered;
    }

    /**
     * Convida (uma única vez) quem pode assinar agora. Em envelopes sequenciais, chamado de novo a cada
     * assinatura, avisa só o próximo grupo. Retorna os e-mails de quem não pôde ser avisado.
     *
     * @return list<string>
     */
    public function inviteActive(Envelope $envelope): array
    {
        $failed = [];

        if ($this->isManual($envelope)) {
            return $failed;
        }

        foreach ($envelope->activeSigners() as $signer) {
            if ($signer->invited_at !== null) {
                continue;
            }

            $url = $signer->signUrl();
            if ($url !== null && $this->invite($signer, basename($url))) {
                $signer->update(['invited_at' => now()]);
            } else {
                $failed[] = $signer->email;
            }
        }

        return $failed;
    }

    private function isManual(Envelope $envelope): bool
    {
        return $envelope->channels() === ['manual'];
    }

    public function otp(Signer $signer, string $code): bool
    {
        return $this->safely(fn () => Mail::to($signer->email, $signer->name)->send(
            new OtpCodeMail($signer->loadMissing('envelope'), $code)
        ), 'otp', $signer);
    }

    private function text(Signer $signer, string $url, bool $reminder): string
    {
        $title = $signer->envelope->title;
        $lead = $reminder ? 'Lembrete: ainda falta a sua assinatura' : 'Você recebeu um documento para assinar';

        return "{$lead} - {$title}. Acesse: {$url}";
    }

    private function viaPhone(Signer $signer, string $channel, string $text): bool
    {
        $to = Phone::normalize($signer->phone);
        if ($to === null) {
            Log::warning("Signatário {$signer->getKey()} sem telefone válido para {$channel}.");

            return false;
        }

        try {
            $this->messages->send($channel, $to, $text);

            return true;
        } catch (MessagingException $e) {
            Log::error("Falha ao enviar {$channel} ao signatário {$signer->getKey()}: ".$e->getMessage());

            return false;
        }
    }

    private function safely(callable $send, string $what, Signer $signer): bool
    {
        try {
            $send();

            return true;
        } catch (Throwable $e) {
            Log::error("Falha ao enviar e-mail ({$what}) ao signatário {$signer->getKey()}: ".$e->getMessage());

            return false;
        }
    }
}
