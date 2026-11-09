<?php

declare(strict_types=1);

namespace App\Signing\Support;

use App\Signing\Data\CertificateInfo;
use App\Signing\Data\SealedDocument;
use App\Signing\Data\SealRequest;
use App\Signing\Exceptions\CertificateExpiredException;
use App\Signing\Exceptions\SigningException;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * Cliente do microserviço interno de selagem PAdES (pyHanko ou DSS/PDFBox).
 *
 * Contrato do microserviço:
 *   POST {url}/v1/seal   (multipart: pdf + metadata JSON)
 *     metadata: { credential: {...}, reason, location, timestamp, ltv }
 *     200 -> { pdf_base64, sealed_at, timestamped, certificate: {...} }
 *     409 -> certificado vencido
 *   POST {url}/v1/certificate  (JSON: { credential: {...} })
 *     200 -> { serial, issuer, subject, valid_from, valid_to, icp_brasil }
 */
final class SealerClient
{
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 60,
    ) {
    }

    /** @param array<string,mixed> $credential */
    public function seal(string $provider, array $credential, SealRequest $request): SealedDocument
    {
        try {
            $response = $this->http
                ->withToken($this->token)
                ->timeout($this->timeout)
                ->attach('pdf', $request->pdf, 'document.pdf')
                ->post($this->baseUrl.'/v1/seal', [
                    'metadata' => json_encode([
                        'credential' => $credential,
                        'reason' => $request->reason,
                        'location' => $request->location,
                        'timestamp' => $request->timestamp,
                        'ltv' => $request->longTermValidation,
                    ], JSON_THROW_ON_ERROR),
                ]);
        } catch (ConnectionException $e) {
            throw new SigningException('Serviço de selagem indisponível.', 0, $e);
        }

        if ($response->status() === 409) {
            throw new CertificateExpiredException('Certificado vencido ou revogado.');
        }

        if ($response->failed()) {
            throw new SigningException('Falha ao selar o PDF (HTTP '.$response->status().').');
        }

        $pdf = base64_decode((string) $response->json('pdf_base64'), true);
        if ($pdf === false || $pdf === '') {
            throw new SigningException('Resposta de selagem inválida.');
        }

        return new SealedDocument(
            pdf: $pdf,
            sha256: hash('sha256', $pdf),
            provider: $provider,
            certificate: CertificateInfo::fromArray((array) $response->json('certificate')),
            sealedAt: new DateTimeImmutable((string) $response->json('sealed_at')),
            timestamped: (bool) $response->json('timestamped'),
        );
    }

    /** @param array<string,mixed> $credential */
    public function certificate(array $credential): CertificateInfo
    {
        try {
            $response = $this->http
                ->withToken($this->token)
                ->timeout(15)
                ->post($this->baseUrl.'/v1/certificate', ['credential' => $credential]);
        } catch (ConnectionException $e) {
            throw new SigningException('Serviço de selagem indisponível.', 0, $e);
        }

        if ($response->failed()) {
            throw new SigningException('Não foi possível ler o certificado (HTTP '.$response->status().').');
        }

        return CertificateInfo::fromArray((array) $response->json());
    }
}
