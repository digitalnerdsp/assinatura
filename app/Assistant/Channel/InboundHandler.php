<?php

declare(strict_types=1);

namespace App\Assistant\Channel;

/** Quem trata uma mensagem recebida (vínculo do número e, depois, o orquestrador). */
interface InboundHandler
{
    public function handle(InboundMessage $message): void;
}
