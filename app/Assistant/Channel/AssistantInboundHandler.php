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
            if (mb_strlen($reply->text) > 1000) { // o corpo da lista é curto: o texto vai inteiro antes
                $this->client->sendText($to, $reply->text);
                $this->client->sendList($to, $reply->list['body'], $reply->list['button'], $reply->list['rows']);

                return;
            }
            $this->client->sendList($to, $reply->text !== '' ? $reply->text : $reply->list['body'], $reply->list['button'], $reply->list['rows']);

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
