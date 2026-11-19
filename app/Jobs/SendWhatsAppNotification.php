<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Assistant\Channel\MetaException;
use App\Assistant\Channel\MetaWhatsAppClient;
use App\Assistant\Notifications\TemplateStore;
use App\Models\User;
use App\Models\WhatsAppNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Envia um aviso já reservado. Uma tentativa só: um envio que a Meta pode ter aceitado nunca é repetido. */
final class SendWhatsAppNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $notificationId)
    {
    }

    public function handle(MetaWhatsAppClient $client, TemplateStore $templates): void
    {
        $n = WhatsAppNotification::query()->find($this->notificationId);
        if ($n === null || $n->status !== 'queued') {
            return;
        }

        // Reivindica antes de enviar: outra execução do mesmo aviso não manda de novo.
        $claimed = WhatsAppNotification::query()->whereKey($n->getKey())->where('status', 'queued')->update(['status' => 'sending']);
        if ($claimed === 0) {
            return;
        }

        $user = User::query()->find($n->user_id);
        $template = $templates->usable($n->template_key);
        if ($user === null || $user->whatsapp_phone === null || $template === null) {
            $n->forceFill(['status' => 'failed', 'reason' => 'Usuário ou modelo indisponível na hora do envio.', 'params' => null])->save();

            return;
        }

        try {
            $id = $client->sendTemplate($user->whatsapp_phone, $template->meta_name, $template->language, array_map('strval', (array) $n->params));
            $n->forceFill(['status' => 'sent', 'wa_message_id' => $id !== '' ? $id : null, 'params' => null])->save();
        } catch (MetaException $e) {
            $n->forceFill(['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 190), 'params' => null])->save();
        }
    }
}
