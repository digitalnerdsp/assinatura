<?php

declare(strict_types=1);

namespace App\Assistant\Channel;

use App\Assistant\AssistantOrchestrator;
use App\Assistant\AssistantReply;
use App\Assistant\Linking\WhatsAppLinker;

/** Mensagem de número vinculado: marca como lida, chama o orquestrador e devolve a resposta pelo WhatsApp. */
final class AssistantInboundHandler implements InboundHandler
{
    public function __construct(
        private readonly WhatsAppLinker $linker,
        private readonly AssistantOrchestrator $orchestrator,
        private readonly MetaWhatsAppClient $client,
    ) {
    }

    public function handle(InboundMessage $message): void
    {
        $user = $this->linker->userFor($message->from);
        if ($user === null) {
            return;
        }

        try {
            $this->client->markRead($message->waMessageId);
        } catch (MetaException $e) {
            report($e);
        }

        $reply = $this->orchestrator->handle($user, $message);

        try {
            $this->send($message->from, $reply);
        } catch (MetaException $e) {
            report($e); // falha ao responder não faz a fila repetir (repetir cobraria a IA de novo)
        }
    }

    private function send(string $to, AssistantReply $reply): void
    {
        if ($reply->list !== null) {
            $this->client->sendList($to, mb_substr($reply->text !== '' ? $reply->text : $reply->list['body'], 0, 1024), $reply->list['button'], $reply->list['rows']);

            return;
        }
        if ($reply->buttons === []) {
            $this->client->sendText($to, $reply->text);

            return;
        }
        if (mb_strlen($reply->text) > 1000) { // o corpo de botões é curto: o texto vai antes
            $this->client->sendText($to, $reply->text);
            $this->client->sendButtons($to, 'Consultas rápidas:', $reply->buttons);

            return;
        }
        $this->client->sendButtons($to, $reply->text, $reply->buttons);
    }
}
