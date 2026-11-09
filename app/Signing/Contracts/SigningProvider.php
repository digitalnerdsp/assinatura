<?php

declare(strict_types=1);

namespace App\Signing\Contracts;

use App\Signing\Data\CertificateInfo;
use App\Signing\Data\SealedDocument;
use App\Signing\Data\SealRequest;

interface SigningProvider
{
    /** Identificador estável gravado em tenant_signing_settings.provider e em final_artifacts. */
    public function name(): string;

    /**
     * Sela o PDF (PAdES) e devolve o documento selado + dados do certificado usado.
     *
     * @throws \App\Signing\Exceptions\SigningException
     * @throws \App\Signing\Exceptions\CertificateExpiredException
     */
    public function seal(SealRequest $request): SealedDocument;

    /** Metadados do certificado que será usado (serial, emissor, validade). */
    public function certificate(): CertificateInfo;

    /** false se o certificado estiver vencido/indisponível. Não lança exceção. */
    public function isAvailable(): bool;
}
