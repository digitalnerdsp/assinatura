<?php

declare(strict_types=1);

namespace App\Assistant\Linking;

use App\Assistant\Channel\InboundHandler;
use App\Assistant\Channel\InboundMessage;
use App\Assistant\Channel\MetaException;
use App\Assistant\Channel\MetaWhatsAppClient;
use Illuminate\Support\Facades\Cache;

/**
 * Primeiro tratador de toda mensagem: número vinculado segue para o assistente; número desconhecido só pode vincular
 * (enviando o código) ou recebe uma orientação, no máximo uma por hora. Número bloqueado não recebe resposta nenhuma.
 */
final class LinkingInboundHandler implements InboundHandler
{
    public function __construct(
        private readonly WhatsAppLinker $linker,
        private readonly MetaWhatsAppClient $client,
        private readonly InboundHandler $next,
    ) {
    }

    public function handle(InboundMessage $message): void
    {
        if ($this->linker->userFor($message->from) !== null) {
            $this->next->handle($message);

            return;
        }
        if ($this->linker->isBlocked($message->from)) {
            return;
        }

        $user = $message->type === 'text' ? $this->linker->claim($message->from, (string) $message->text) : null;
        if ($user !== null) {
            $first = explode(' ', trim($user->name))[0];
            $this->reply($message->from, "Tudo certo, {$first}! Seu WhatsApp está vinculado. Pergunte, por exemplo: \"quais documentos estão pendentes?\"");

            return;
        }

        if (Cache::add('wa-orient:'.$message->from, 1, 3600)) {
            $this->reply($message->from, 'Para usar o assistente, vincule este número em Meu perfil (seção Assistente no WhatsApp): gere o código e envie-o aqui.');
        }
    }

    private function reply(string $to, string $text): void
    {
        try {
            $this->client->sendText($to, $text);
        } catch (MetaException $e) {
            report($e); // falha ao responder nunca derruba o processamento
        }
    }
}
