<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Assistant\AssistantSettings;
use App\Assistant\Channel\InboundMessage;
use App\Assistant\Channel\StatusProcessor;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundWhatsApp;
use App\Models\WhatsAppInbound;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** Webhook da API de Nuvem do WhatsApp: verificação, mensagens recebidas e status com preço. */
final class MetaWebhookController extends Controller
{
    public function __construct(private readonly AssistantSettings $settings, private readonly StatusProcessor $statuses)
    {
    }

    /** A Meta confere o endereço: devolve o desafio só se o token de verificação for o configurado. */
    public function verify(Request $request): Response
    {
        $expected = $this->settings->metaVerifyToken();
        $given = (string) $request->query('hub_verify_token', '');

        // PHP troca "." por "_" nos nomes dos parâmetros: hub.mode chega como hub_mode.
        if ($expected === '' || $request->query('hub_mode') !== 'subscribe' || ! hash_equals($expected, $given)) {
            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge', ''), 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request): Response
    {
        $raw = $request->getContent();
        if (! $this->validSignature($raw, (string) $request->header('X-Hub-Signature-256', ''))) {
            return response('Unauthorized', 401);
        }

        $body = json_decode($raw, true);
        if (! is_array($body)) {
            return response('Bad Request', 400);
        }

        $configured = $this->settings->metaPhoneNumberId();
        foreach ((array) ($body['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                if ($configured !== '' && ($value['metadata']['phone_number_id'] ?? '') !== $configured) {
                    continue; // evento de outro número da Meta
                }
                foreach ((array) ($value['messages'] ?? []) as $m) {
                    if (is_array($m)) {
                        $this->store(InboundMessage::fromMeta($m));
                    }
                }
                foreach ((array) ($value['statuses'] ?? []) as $s) {
                    if (is_array($s)) {
                        $this->statuses->handle($s);
                    }
                }
            }
        }

        return response('', 200);
    }

    /** Assinatura HMAC-SHA256 do corpo exatamente como chegou, com o segredo do app. Sem segredo configurado, nada passa. */
    private function validSignature(string $raw, string $header): bool
    {
        $secret = $this->settings->metaAppSecret();
        if ($secret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $raw, $secret), substr($header, 7));
    }

    private function store(InboundMessage $message): void
    {
        if ($message->waMessageId === '' || $message->from === '') {
            return;
        }

        $id = (string) Str::uuid();
        $now = now();

        // INSERT ... ON CONFLICT DO NOTHING: se a Meta repetiu a notificação, nada é gravado nem despachado.
        $inserted = WhatsAppInbound::query()->insertOrIgnore([[
            'id' => $id, 'wa_message_id' => $message->waMessageId, 'from_phone' => $message->from, 'type' => $message->type,
            'payload' => json_encode($message->payload()), 'received_at' => CarbonImmutable::createFromTimestampUTC($message->timestamp ?: time()),
            'created_at' => $now, 'updated_at' => $now,
        ]]);

        if ($inserted === 1) {
            ProcessInboundWhatsApp::dispatch($id);
        }
    }
}
