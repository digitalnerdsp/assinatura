<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\Presenter;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Gerenciamento de webhooks pela API (o segredo é devolvido só na criação). */
final class WebhookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = WebhookEndpoint::query()->where('tenant_id', $this->tenant($request)->getKey())->latest()->get();

        return response()->json(['data' => $items->map(fn ($w) => Presenter::webhook($w))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'events' => ['nullable', 'array'],
            'events.*' => ['in:'.implode(',', array_keys(config('webhooks.events')))],
        ]);

        if (($problem = UrlGuard::problem($data['url'])) !== null) {
            return response()->json(['error' => 'invalid_url', 'message' => $problem], 422);
        }

        $secret = 'whsec_'.Str::random(40);
        $endpoint = WebhookEndpoint::query()->create([
            'tenant_id' => $this->tenant($request)->getKey(),
            'url' => $data['url'],
            'secret' => $secret,
            'events' => ($data['events'] ?? []) === [] ? null : array_values($data['events']),
            'active' => true,
        ]);

        return response()->json(Presenter::webhook($endpoint) + ['secret' => $secret], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $endpoint->update(['active' => $data['active']]);

        return response()->json(Presenter::webhook($endpoint->fresh()));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return response()->json(null, 204);
    }

    /** Envia um evento "ping" ao endpoint. */
    public function test(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);

        $delivery = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->getKey(),
            'tenant_id' => $endpoint->tenant_id,
            'event' => 'ping',
            'payload' => ['message' => 'Teste de webhook'],
            'status' => 'pending',
        ]);
        DeliverWebhook::dispatch($delivery->getKey());

        return response()->json(['delivery_id' => $delivery->getKey()], 202);
    }

    public function deliveries(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);

        $items = $endpoint->deliveries()->latest()->limit(50)->get()->map(fn (WebhookDelivery $d) => [
            'id' => $d->getKey(), 'event' => $d->event, 'status' => $d->status, 'attempts' => $d->attempts,
            'response_code' => $d->response_code, 'error' => $d->error, 'created_at' => $d->created_at?->toIso8601String(),
            'delivered_at' => $d->delivered_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $items->all()]);
    }

    private function find(Request $request, string $id): WebhookEndpoint
    {
        abort_unless(Str::isUuid($id), 404);

        return WebhookEndpoint::query()->where('tenant_id', $this->tenant($request)->getKey())->findOrFail($id);
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }
}
