<?php

declare(strict_types=1);

namespace App\Signing\Providers;

use App\Signing\Support\SealerClient;

/**
 * Chave da plataforma em HSM/KMS (alvo de produção). A chave nunca sai do HSM.
 */
final class PlatformHsmProvider extends AbstractSealerProvider
{
    public const NAME = 'platform_hsm';

    public function __construct(SealerClient $sealer, private readonly string $keyId)
    {
        parent::__construct($sealer);
    }

    public function name(): string
    {
        return self::NAME;
    }

    protected function credential(): array
    {
        return ['type' => 'hsm_key', 'key_id' => $this->keyId];
    }
}
