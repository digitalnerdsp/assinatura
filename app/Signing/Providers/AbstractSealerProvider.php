<?php

declare(strict_types=1);

namespace App\Signing\Providers;

use App\Signing\Contracts\SigningProvider;
use App\Signing\Data\CertificateInfo;
use App\Signing\Data\SealedDocument;
use App\Signing\Data\SealRequest;
use App\Signing\Exceptions\CertificateExpiredException;
use App\Signing\Exceptions\SigningException;
use App\Signing\Support\SealerClient;
use DateTimeImmutable;

abstract class AbstractSealerProvider implements SigningProvider
{
    private ?CertificateInfo $cached = null;

    public function __construct(protected readonly SealerClient $sealer)
    {
    }

    /**
     * Referência da credencial enviada ao microserviço (caminho no cofre, key id do KMS/HSM,
     * token do provedor em nuvem...). Nunca contém a chave privada em texto.
     *
     * @return array<string,mixed>
     */
    abstract protected function credential(): array;

    public function seal(SealRequest $request): SealedDocument
    {
        $certificate = $this->certificate();

        if (! $certificate->isValidAt(new DateTimeImmutable())) {
            throw new CertificateExpiredException(
                "Certificado {$certificate->serial} fora da validade ({$this->name()})."
            );
        }

        return $this->sealer->seal($this->name(), $this->credential(), $request);
    }

    public function certificate(): CertificateInfo
    {
        return $this->cached ??= $this->sealer->certificate($this->credential());
    }

    public function isAvailable(): bool
    {
        try {
            return $this->certificate()->isValidAt(new DateTimeImmutable());
        } catch (SigningException) {
            return false;
        }
    }
}
