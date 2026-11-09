<?php

declare(strict_types=1);

namespace App\Signing\Data;

use DateTimeImmutable;

final readonly class SealedDocument
{
    public function __construct(
        public string $pdf,
        public string $sha256,
        public string $provider,
        public CertificateInfo $certificate,
        public DateTimeImmutable $sealedAt,
        public bool $timestamped,
    ) {
    }
}
