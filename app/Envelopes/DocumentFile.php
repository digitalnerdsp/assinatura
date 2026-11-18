<?php

declare(strict_types=1);

namespace App\Envelopes;

/** Um arquivo do envio, principal ou acrescentado, na mesma forma. `n` é a posição de 1 em diante; `extraId` é nulo no principal. */
final class DocumentFile
{
    public function __construct(
        public readonly int $n,
        public readonly ?string $extraId,
        public readonly string $name,
        public readonly string $storagePath,
        public readonly string $sha256,
        public readonly int $size,
        public readonly ?string $finalPath,
        public readonly ?string $finalSha256,
        public readonly ?int $finalSize,
    ) {
    }
}
