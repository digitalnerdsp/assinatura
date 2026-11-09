<?php

declare(strict_types=1);

namespace App\Signing;

use App\Models\TenantSigningSetting;
use App\Signing\Contracts\SigningProvider;
use App\Signing\Data\SealedDocument;
use App\Signing\Data\SealRequest;
use App\Signing\Exceptions\ProviderNotAllowedException;
use App\Signing\Exceptions\SigningException;
use App\Signing\Providers\LocalTestProvider;
use App\Signing\Providers\PlatformA1Provider;
use App\Signing\Providers\PlatformHsmProvider;
use App\Signing\Providers\TenantCloudCertProvider;
use App\Signing\Support\SealerClient;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * Escolhe o provider de selagem de cada tenant (modelo híbrido) e aplica a política de fallback.
 */
final class SigningManager
{
    public function __construct(
        private readonly SealerClient $sealer,
        private readonly LoggerInterface $logger,
        /** @var array<string,mixed> config('signing') */
        private readonly array $config,
        /** Permite trocar a construção de providers em testes. */
        private readonly ?Closure $factory = null,
    ) {
    }

    /** Provider padrão da plataforma, definido em config/signing.php. */
    public function platform(): SigningProvider
    {
        return $this->make((string) $this->config['platform_provider']);
    }

    public function forTenant(?TenantSigningSetting $setting): SigningProvider
    {
        if ($setting === null || $setting->provider !== TenantCloudCertProvider::NAME) {
            return $this->platform();
        }

        return $this->make(TenantCloudCertProvider::NAME, $setting->credential_ref ?? []);
    }

    /**
     * Sela o PDF com o provider do tenant. Se o certificado do tenant falhar, só usa o da
     * plataforma quando a política do tenant for "use_platform"; caso contrário relança.
     */
    public function seal(?TenantSigningSetting $setting, SealRequest $request): SealedDocument
    {
        $provider = $this->forTenant($setting);

        try {
            return $provider->seal($request);
        } catch (SigningException $e) {
            $canFallback = $provider->name() !== $this->platform()->name()
                && $setting?->fallback_policy === TenantSigningSetting::FALLBACK_USE_PLATFORM;

            if (! $canFallback) {
                throw $e;
            }

            $this->logger->warning('Fallback para certificado da plataforma', [
                'tenant_id' => $setting->tenant_id,
                'provider' => $provider->name(),
                'error' => $e->getMessage(),
            ]);

            return $this->platform()->seal($request);
        }
    }

    /** @param array<string,mixed> $credentialRef */
    private function make(string $name, array $credentialRef = []): SigningProvider
    {
        if ($this->factory !== null) {
            return ($this->factory)($name, $credentialRef);
        }

        return match ($name) {
            LocalTestProvider::NAME => new LocalTestProvider(
                $this->sealer,
                allowed: (bool) $this->config['allow_local_test'],
            ),
            PlatformA1Provider::NAME => new PlatformA1Provider(
                $this->sealer,
                (string) $this->config['platform_a1_secret_ref'],
            ),
            PlatformHsmProvider::NAME => new PlatformHsmProvider(
                $this->sealer,
                (string) $this->config['platform_hsm_key_id'],
            ),
            TenantCloudCertProvider::NAME => new TenantCloudCertProvider($this->sealer, $credentialRef),
            default => throw new ProviderNotAllowedException("Provider de assinatura desconhecido: {$name}"),
        };
    }
}
