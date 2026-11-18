<?php

declare(strict_types=1);

namespace App\Templates;

use RuntimeException;

/** O valor digitado não cabe na lacuna nem com a letra mínima. */
final class ValueDoesNotFit extends RuntimeException
{
    public function __construct(public readonly string $fieldKey, public readonly string $label)
    {
        parent::__construct("O valor de “{$label}” não cabe na lacuna. Escreva menos texto.");
    }
}
