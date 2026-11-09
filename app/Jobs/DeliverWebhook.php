<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Webhooks\UrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Entrega um evento. Cabeçalhos: X-Webhook-Id (idempotência), X-Webhook-Event, X-Webhook-Timestamp e
 * X-Webhook-Signature = "sha256=" + HMAC-SHA256("{timestamp}.{corpo}", segredo do endpoint).
 */
final class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 7200];
    }

    public function __construct(public readonly string $deliveryId)
    {
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if ($delivery === null || $delivery->status === 'delivered') {
            return;
        }

        $endpoint = $delivery->endpoint;
        if ($endpoint === null || ! $endpoint->active) {
            $delivery->update(['status' => 'failed', 'error' => 'Endpoint removido ou desativado.']);

            return;
        }

        $delivery->increment('attempts');

        if (($problem = UrlGuard::problem($endpoint->url)) !== null) {
            $delivery->update(['status' => 'failed', 'error' => $problem]);

            return; // não adianta tentar de novo
        }

        $timestamp = (string) time();
        $body = json_encode([
            'id' => $delivery->getKey(),
            'event' => $delivery->event,
            'created_at' => $delivery->created_at->toIso8601String(),
            'data' => $delivery->payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('app.name', 'Assinaturas').'-Webhook/1.0',
                'X-Webhook-Id' => $delivery->getKey(),
                'X-Webhook-Event' => $delivery->event,
                'X-Webhook-Timestamp' => $timestamp,
                'X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->secret),
            ])->timeout((int) config('webhooks.timeout'))->withBody($body, 'application/json')->post($endpoint->url);
        } catch (Throwable $e) {
            $this->failOrRetry($delivery, null, $e->getMessage());

            return;
        }

        if ($response->successful()) {
            $delivery->update(['status' => 'delivered', 'response_code' => $response->status(), 'error' => null, 'delivered_at' => now()]);

            return;
        }

        $this->failOrRetry($delivery, $response->status(), 'Resposta HTTP '.$response->status());
    }

    private function failOrRetry(WebhookDelivery $delivery, ?int $code, string $error): void
    {
        $delivery->update(['response_code' => $code, 'error' => mb_substr($error, 0, 500)]);

        if ($delivery->attempts >= $this->tries) {
            $delivery->update(['status' => 'failed']);

            return;
        }

        $this->release($this->backoff()[min($delivery->attempts - 1, 4)]);
    }
}
