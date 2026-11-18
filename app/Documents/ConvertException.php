<?php

declare(strict_types=1);

namespace App\Documents;

use RuntimeException;

/** O selador recusou a conversão do DOCX: invalid_docx, timeout ou convert_failed. */
final class ConvertException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
