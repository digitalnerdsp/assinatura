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
                        'report' => $request->report ?: null,
                    ], JSON_THROW_ON_ERROR),
                ]);
        } catch (ConnectionException $e) {
            throw new SigningException('Serviço de selagem indisponível: '.$this->short($e->getMessage()), 0, $e);
        }

        if ($response->status() === 409) {
            throw new CertificateExpiredException('Certificado vencido ou revogado.');
        }

        if ($response->failed()) {
            throw new SigningException('Falha ao selar o PDF (HTTP '.$response->status().'): '.$this->detail($response));
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
            decorated: (bool) $response->json('decorated', true),
        );
    }

    /**
     * Escreve textos nas lacunas de um PDF (POST /v1/fill). Cada campo: key, page (1..n), x, y, width, height (frações,
     * origem no topo à esquerda), text, multiline. Lista vazia só devolve o PDF e o total de páginas.
     *
     * @param  list<array<string,mixed>>  $fields
     * @return array{pdf:string,pages:int}
     *
     * @throws \App\Templates\FillException texto que não cabe ou PDF inválido (422)
     * @throws SigningException serviço indisponível ou com erro
     */
    public function fill(string $pdf, array $fields): array
    {
        try {
            $response = $this->http
                ->withToken($this->token)
                ->timeout($this->timeout)
                ->attach('pdf', $pdf, 'document.pdf')
                ->post($this->baseUrl.'/v1/fill', ['fields' => json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        } catch (ConnectionException $e) {
            throw new SigningException('Serviço de selagem indisponível: '.$this->short($e->getMessage()), 0, $e);
        }

        if ($response->status() === 422) {
            throw new \App\Templates\FillException((string) $response->json('error', 'invalid_pdf'), $response->json('field'));
        }

        if ($response->failed()) {
            throw new SigningException('Falha ao preencher o PDF (HTTP '.$response->status().'): '.$this->detail($response));
        }

        return ['pdf' => $response->body(), 'pages' => (int) $response->header('X-Page-Count')];
    }

    /**
     * Converte um DOCX em PDF (LibreOffice dentro do selador).
     *
     * @throws \App\Documents\ConvertException arquivo inválido, tempo esgotado ou falha na conversão (422)
     * @throws SigningException serviço indisponível ou sem LibreOffice
     */
    public function convert(string $docx): string
    {
        try {
            $response = $this->http
                ->withToken($this->token)
                ->timeout(90)
                ->attach('docx', $docx, 'document.docx')
                ->post($this->baseUrl.'/v1/convert');
        } catch (ConnectionException $e) {
            throw new SigningException('Serviço de selagem indisponível: '.$this->short($e->getMessage()), 0, $e);
        }

        if ($response->status() === 422) {
            throw new \App\Documents\ConvertException((string) $response->json('error', 'convert_failed'));
        }

        if ($response->failed()) {
            throw new SigningException('Falha ao converter o DOCX (HTTP '.$response->status().'): '.$this->detail($response));
        }

        return $response->body();
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
            throw new SigningException('Serviço de selagem indisponível: '.$this->short($e->getMessage()), 0, $e);
        }

        if ($response->failed()) {
            throw new SigningException('Não foi possível ler o certificado (HTTP '.$response->status().'): '.$this->detail($response));
        }

        return CertificateInfo::fromArray((array) $response->json());
    }

    /** Verifica se o serviço responde (GET /health). Lança SigningException com o motivo se não. */
    public function health(): void
    {
        try {
            $response = $this->http->timeout(10)->get($this->baseUrl.'/health');
        } catch (ConnectionException $e) {
            throw new SigningException('Serviço de selagem indisponível: '.$this->short($e->getMessage()), 0, $e);
        }

        if ($response->failed()) {
            throw new SigningException('Serviço respondeu HTTP '.$response->status().': '.$this->detail($response));
        }
    }

    private function detail(\Illuminate\Http\Client\Response $response): string
    {
        $detail = $response->json('detail');
        $text = is_string($detail) ? $detail : trim($response->body());

        return $text === '' ? 'sem detalhes' : $this->short($text);
    }

    private function short(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > 300 ? mb_substr($text, 0, 300).'…' : $text;
    }
}
