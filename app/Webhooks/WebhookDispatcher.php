<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Registra e enfileira a entrega dos eventos aos endpoints do cliente. Nunca interrompe o fluxo principal. */
final class WebhookDispatcher
{
    public function dispatch(Envelope $envelope, string $event, ?Signer $signer = null): void
    {
        try {
            $endpoints = WebhookEndpoint::query()
                ->where('tenant_id', $envelope->tenant_id)
                ->where('active', true)
                ->get()
                ->filter(fn (WebhookEndpoint $e) => $e->listensTo($event));

            if ($endpoints->isEmpty()) {
                return;
            }

            $data = EnvelopePayload::make($envelope, $signer);

            foreach ($endpoints as $endpoint) {
                $delivery = WebhookDelivery::query()->create([
                    'webhook_endpoint_id' => $endpoint->getKey(),
                    'tenant_id' => $envelope->tenant_id,
                    'event' => $event,
                    'payload' => $data,
                    'status' => 'pending',
                ]);

                DeliverWebhook::dispatch($delivery->getKey())->afterCommit();
            }
        } catch (Throwable $e) {
            Log::error("Falha ao registrar webhook {$event}: ".$e->getMessage());
        }
    }
}
