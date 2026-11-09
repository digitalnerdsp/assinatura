<?php

declare(strict_types=1);

namespace App\Signing\Providers;

use App\Signing\Data\SealedDocument;
use App\Signing\Data\SealRequest;
use App\Signing\Exceptions\ProviderNotAllowedException;
use App\Signing\Support\SealerClient;

/**
 * Certificado autoassinado. SEM validade ICP-Brasil. Bloqueado em produção.
 */
final class LocalTestProvider extends AbstractSealerProvider
{
    public const NAME = 'local_test';

    public function __construct(SealerClient $sealer, private readonly bool $allowed)
    {
        parent::__construct($sealer);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function seal(SealRequest $request): SealedDocument
    {
        $this->guard();

        return parent::seal($request);
    }

    public function isAvailable(): bool
    {
        return $this->allowed && parent::isAvailable();
    }

    protected function credential(): array
    {
        $this->guard();

        return ['type' => 'self_signed_test'];
    }

    private function guard(): void
    {
        if (! $this->allowed) {
            throw new ProviderNotAllowedException(
                'LocalTestProvider não pode ser usado neste ambiente (sem validade jurídica).'
            );
        }
    }
}
