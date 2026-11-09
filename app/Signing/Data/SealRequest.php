<?php

declare(strict_types=1);

namespace App\Signing\Data;

final readonly class SealRequest
{
    public function __construct(
        /** Conteúdo binário do PDF a selar. */
        public string $pdf,
        public string $reason = 'Documento finalizado e selado pela plataforma',
        public string $location = 'Brasil',
        /** Solicita carimbo do tempo RFC 3161 (ACT). */
        public bool $timestamp = true,
        /** Incorpora cadeia/OCSP/CRL (PAdES-LT) para validação de longo prazo. */
        public bool $longTermValidation = true,
        /**
         * Dados para o carimbo visível e a página de relatório de assinaturas (ver App\Signing\ReportBuilder).
         * Vazio = sela sem decorar o PDF.
         *
         * @var array<string,mixed>
         */
        public array $report = [],
    ) {
    }
}
