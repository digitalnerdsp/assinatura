<?php

declare(strict_types=1);

namespace App\Signing\Providers;

use App\Signing\Support\SealerClient;

/**
 * Certificado em nuvem do próprio cliente (BirdID, VIDaaS, SafeID, RemoteID...),
 * autorizado pelo tenant via API do provedor.
 */
final class TenantCloudCertProvider extends AbstractSealerProvider
{
    public const NAME = 'tenant_cloud';

    /** @param array<string,mixed> $credentialRef ex.: ['vendor' => 'vidaas', 'authorization_id' => '...'] */
    public function __construct(SealerClient $sealer, private readonly array $credentialRef)
    {
        parent::__construct($sealer);
    }

    public function name(): string
    {
        return self::NAME;
    }

    protected function credential(): array
    {
        return ['type' => 'cloud_cert'] + $this->credentialRef;
    }
}
