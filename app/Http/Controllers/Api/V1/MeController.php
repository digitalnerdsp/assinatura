<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Confere a chave e mostra a conta e o consumo do mês. Bom para "testar conexão" em integrações. */
final class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $key = $request->attributes->get('api_key');

        return response()->json([
            'api_version' => 'v1',
            'tenant' => ['id' => $tenant->getKey(), 'name' => $tenant->name],
            'key' => ['name' => $key->name, 'prefix' => $key->prefix, 'read_only' => $key->read_only, 'can_sign_as_company' => $key->can_sign_as_company],
            'usage' => [
                'envelopes_sent_this_month' => Envelope::query()->where('tenant_id', $tenant->getKey())->where('sent_at', '>=', now()->startOfMonth())->count(),
                'monthly_envelope_limit' => $tenant->monthly_envelope_limit,
                'max_pdf_mb' => (int) config('signing.max_pdf_mb'),
            ],
            'webhook_events' => array_keys(config('webhooks.events')),
        ]);
    }
}
