<?php

declare(strict_types=1);

namespace App\Assistant\Channel;

/** Padrão até o orquestrador existir: não faz nada. */
final class NullInboundHandler implements InboundHandler
{
    public function handle(InboundMessage $message): void
    {
    }
}
