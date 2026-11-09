<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\UrlGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class WebhookController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = $request->user()->tenant_id;

        return view('webhooks.index', [
            'endpoints' => WebhookEndpoint::query()->where('tenant_id', $tenantId)->latest()->get(),
            'deliveries' => WebhookDelivery::query()->where('tenant_id', $tenantId)->with('endpoint')->latest()->limit(25)->get(),
            'events' => config('webhooks.events'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'events' => ['nullable', 'array'],
            'events.*' => ['in:'.implode(',', array_keys(config('webhooks.events')))],
        ]);

        if (($problem = UrlGuard::problem($data['url'])) !== null) {
            return back()->withInput()->withErrors(['url' => $problem]);
        }

        $secret = 'whsec_'.Str::random(40);
        WebhookEndpoint::query()->create([
            'tenant_id' => $request->user()->tenant_id,
            'url' => $data['url'],
            'secret' => $secret,
            'events' => ($data['events'] ?? []) === [] ? null : array_values($data['events']),
            'active' => true,
        ]);

        // O segredo é mostrado uma única vez, como as chaves de API.
        return back()->with('status', 'Webhook criado.')->with('new_secret', $secret);
    }

    public function toggle(Request $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        $this->owned($request, $endpoint);
        $endpoint->update(['active' => ! $endpoint->active]);

        return back()->with('status', $endpoint->active ? 'Webhook ativado.' : 'Webhook desativado.');
    }

    public function destroy(Request $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        $this->owned($request, $endpoint);
        $endpoint->delete();

        return back()->with('status', 'Webhook removido.');
    }

    /** Envia um evento "ping" para conferir a URL e a verificação da assinatura. */
    public function test(Request $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        $this->owned($request, $endpoint);

        $delivery = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->getKey(),
            'tenant_id' => $endpoint->tenant_id,
            'event' => 'ping',
            'payload' => ['message' => 'Teste de webhook'],
            'status' => 'pending',
        ]);
        DeliverWebhook::dispatch($delivery->getKey());

        return back()->with('status', 'Evento de teste enfileirado. Veja o resultado em "Últimas entregas".');
    }

    public function redeliver(Request $request, WebhookDelivery $delivery): RedirectResponse
    {
        abort_unless($delivery->tenant_id === $request->user()->tenant_id, 404);

        $delivery->update(['status' => 'pending', 'attempts' => 0, 'error' => null]);
        DeliverWebhook::dispatch($delivery->getKey());

        return back()->with('status', 'Entrega reenfileirada.');
    }

    private function owned(Request $request, WebhookEndpoint $endpoint): void
    {
        abort_unless($endpoint->tenant_id === $request->user()->tenant_id, 404);
    }
}
