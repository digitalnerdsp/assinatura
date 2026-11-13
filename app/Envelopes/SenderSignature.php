<?php

declare(strict_types=1);

namespace App\Envelopes;

/** Assinatura do remetente (a empresa): o que assinar e como ficou provado (sessão logada ou chave de API). */
final readonly class SenderSignature
{
    /**
     * @param  'drawn'|'typed'  $type
     * @param  'session'|'api_key'  $method
     */
    public function __construct(
        public string $type,
        public string $data,
        public string $method,
        public ?int $userId = null,
        public ?string $apiKeyId = null,
    ) {
    }
}
