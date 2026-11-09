<?php

declare(strict_types=1);

use App\Models\TenantSigningSetting;
use App\Signing\Contracts\SigningProvider;
use App\Signing\Data\CertificateInfo;
use App\Signing\Data\SealedDocument;
use App\Signing\Data\SealRequest;
use App\Signing\Exceptions\CertificateExpiredException;
use App\Signing\Exceptions\ProviderNotAllowedException;
use App\Signing\Providers\LocalTestProvider;
use App\Signing\SigningManager;
use App\Signing\Support\SealerClient;
use Illuminate\Http\Client\Factory as Http;
use Psr\Log\NullLogger;

function fakeCert(): CertificateInfo
{
    return new CertificateInfo('1', 'AC Teste', 'CN=Teste', new DateTimeImmutable('-1 year'), new DateTimeImmutable('+1 year'), false);
}

function fakeProvider(string $name, bool $fails): SigningProvider
{
    return new class($name, $fails) implements SigningProvider {
        public function __construct(private string $n, private bool $fails)
        {
        }

        public function name(): string
        {
            return $this->n;
        }

        public function seal(SealRequest $request): SealedDocument
        {
            if ($this->fails) {
                throw new CertificateExpiredException('vencido');
            }

            return new SealedDocument('sealed', hash('sha256', 'sealed'), $this->n, fakeCert(), new DateTimeImmutable(), true);
        }

        public function certificate(): CertificateInfo
        {
            return fakeCert();
        }

        public function isAvailable(): bool
        {
            return ! $this->fails;
        }
    };
}

function manager(array $config = []): SigningManager
{
    return new SigningManager(
        new SealerClient(new Http(), 'http://sealer', 'token'),
        new NullLogger(),
        $config + ['platform_provider' => 'platform_a1', 'allow_local_test' => false],
        fn (string $name) => fakeProvider($name, fails: $name === 'tenant_cloud'),
    );
}

function setting(string $fallback): TenantSigningSetting
{
    return new TenantSigningSetting([
        'tenant_id' => 'tenant-1',
        'provider' => 'tenant_cloud',
        'credential_ref' => ['vendor' => 'vidaas'],
        'fallback_policy' => $fallback,
    ]);
}

it('usa o certificado da plataforma quando o tenant não tem configuração', function () {
    expect(manager()->seal(null, new SealRequest('%PDF'))->provider)->toBe('platform_a1');
});

it('relança o erro quando o certificado do tenant falha e a política é block', function () {
    manager()->seal(setting(TenantSigningSetting::FALLBACK_BLOCK), new SealRequest('%PDF'));
})->throws(CertificateExpiredException::class);

it('cai para a plataforma quando o tenant autorizou o fallback', function () {
    $sealed = manager()->seal(setting(TenantSigningSetting::FALLBACK_USE_PLATFORM), new SealRequest('%PDF'));

    expect($sealed->provider)->toBe('platform_a1');
});

it('bloqueia o LocalTestProvider quando não permitido', function () {
    $provider = new LocalTestProvider(new SealerClient(new Http(), 'http://sealer', 'token'), allowed: false);

    $provider->seal(new SealRequest('%PDF'));
})->throws(ProviderNotAllowedException::class);
