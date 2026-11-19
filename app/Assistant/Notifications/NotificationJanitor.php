<?php

declare(strict_types=1);

namespace App\Assistant\Notifications;

use App\Jobs\SendWhatsAppNotification;
use App\Models\WhatsAppNotification;
use Throwable;

/** Nada fica preso: o aviso que ficou na fila (fila fora do ar) é reenviado e o que ficou "enviando" (processo morto) vira interrompido. */
final class NotificationJanitor
{
    private const MINUTES = 10;

    /** @return array{requeued:int,interrupted:int} */
    public function run(): array
    {
        $cutoff = now()->subMinutes(self::MINUTES);
        $interrupted = 0;
        $requeued = 0;

        // Enviando há tempo demais: pode ou não ter saído, então nunca reenvia (evita mensagem em dobro).
        WhatsAppNotification::query()->where('status', 'sending')->where('updated_at', '<', $cutoff)->each(function (WhatsAppNotification $n) use (&$interrupted): void {
            $n->forceFill(['status' => 'failed', 'reason' => 'Envio interrompido: o resultado é desconhecido e não foi repetido.', 'params' => null])->save();
            $interrupted++;
        });

        // Na fila há tempo demais: nunca começou, o job reivindica de forma atômica e é seguro repetir.
        WhatsAppNotification::query()->where('status', 'queued')->where('updated_at', '<', $cutoff)->each(function (WhatsAppNotification $n) use (&$requeued): void {
            try {
                SendWhatsAppNotification::dispatchSync($n->getKey());
                $requeued++;
            } catch (Throwable $e) {
                report($e);
            }
        });

        return ['requeued' => $requeued, 'interrupted' => $interrupted];
    }
}
