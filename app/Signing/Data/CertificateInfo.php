<?php

declare(strict_types=1);

namespace App\Signing\Data;

use DateTimeImmutable;

final readonly class CertificateInfo
{
    public function __construct(
        public string $serial,
        public string $issuer,
        public string $subject,
        public DateTimeImmutable $validFrom,
        public DateTimeImmutable $validTo,
        public bool $icpBrasil,
    ) {
    }

    public function isValidAt(DateTimeImmutable $moment): bool
    {
        return $moment >= $this->validFrom && $moment <= $this->validTo;
    }

    public function daysUntilExpiry(DateTimeImmutable $now): int
    {
        return (int) $now->diff($this->validTo)->format('%r%a');
    }

    /** @param array{serial:string,issuer:string,subject:string,valid_from:string,valid_to:string,icp_brasil:bool} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['serial'],
            $data['issuer'],
            $data['subject'],
            new DateTimeImmutable($data['valid_from']),
            new DateTimeImmutable($data['valid_to']),
            (bool) $data['icp_brasil'],
        );
    }
}
