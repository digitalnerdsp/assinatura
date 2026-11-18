<?php

declare(strict_types=1);

namespace App\Templates;

use RuntimeException;

/** O serviço de selagem recusou o preenchimento: o texto não cabe numa lacuna (does_not_fit) ou o PDF é inválido (invalid_pdf). */
final class FillException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $fieldKey = null)
    {
        parent::__construct($reason.($fieldKey !== null ? ': '.$fieldKey : ''));
    }
}
