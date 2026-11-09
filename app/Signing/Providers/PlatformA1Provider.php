<?php

declare(strict_types=1);

namespace App\Signing\Providers;

use App\Signing\Support\SealerClient;

/**
 * e-CNPJ A1 da plataforma. O .pfx e a senha ficam no cofre do microserviço;
 * aqui trafega só a referência.
 */
final class PlatformA1Provider extends AbstractSealerProvider
{
    public const NAME = 'platform_a1';

    public function __construct(SealerClient $sealer, private readonly string $secretRef)
    {
        parent::__construct($sealer);
    }

    public function name(): string
    {
        return self::NAME;
    }

    protected function credential(): array
    {
        return ['type' => 'pkcs12_ref', 'secret_ref' => $this->secretRef];
    }
}
