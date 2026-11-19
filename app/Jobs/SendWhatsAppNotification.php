<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Assistant\Channel\MetaException;
use App\Assistant\Channel\MetaWhatsAppClient;
use App\Assistant\Notifications\TemplateStore;
use App\Assistant\Notifications\WhatsAppNotifier;
use App\Models\User;
use App\Models\WhatsAppNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Envia um aviso já reservado. Uma tentativa só: um envio que a Meta pode ter aceitado nunca é repetido. */
final class SendWhatsAppNotification implements ShouldQueue
{
    use Queueable;

    /** Erros da Meta que dizem que o modelo não pode mais ser usado (pausado, desativado). */
    private const TEMPLATE_UNUSABLE = [132015 => 'pausado', 132016 => 'pausado'];

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $notificationId)
    {
    }

    public function handle(MetaWhatsAppClient $client, TemplateStore $templates, WhatsAppNotifier $notifier): void
    {
        $n = WhatsAppNotification::query()->find($this->notificationId);
        if ($n === null || $n->status !== 'queued') {
            return;
        }

        // Reivindica antes de enviar: outra execução do mesmo aviso não manda de novo.
        $claimed = WhatsAppNotification::query()->whereKey($n->getKey())->where('status', 'queued')->update(['status' => 'sending', 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        try {
            $user = User::query()->find($n->user_id);
            // Quem pode receber é conferido de novo: o usuário pode ter sido desativado, a empresa suspensa ou o aviso desligado desde que entrou na fila.
            if (($why = $notifier->skipReason($user, $n->template_key)) !== null) {
                $this->fail($n, 'Não enviado: '.$why.' na hora do envio.');

                return;
            }
            $template = $templates->usable($n->template_key);
            $id = $client->sendTemplate($user->whatsapp_phone, $template->meta_name, $template->language, array_map('strval', (array) $n->params));
            $n->forceFill(['status' => 'sent', 'wa_message_id' => $id !== '' ? $id : null, 'params' => null])->save();
        } catch (MetaException $e) {
            if (isset(self::TEMPLATE_UNUSABLE[$e->metaCode ?? 0]) && ($t = $templates->usable($n->template_key)) !== null) {
                $t->forceFill(['status' => self::TEMPLATE_UNUSABLE[$e->metaCode], 'enabled' => false])->save(); // a Meta tirou o modelo de uso
            }
            $this->fail($n, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->fail($n, 'Erro inesperado no envio: '.$e->getMessage());
        }
    }

    private function fail(WhatsAppNotification $n, string $reason): void
    {
        $n->forceFill(['status' => 'failed', 'reason' => mb_substr($reason, 0, 190), 'params' => null])->save();
    }
}
