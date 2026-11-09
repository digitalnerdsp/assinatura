<?php

declare(strict_types=1);

namespace App\Enums;

enum SigningOrder: string
{
    case Parallel = 'parallel';
    case Sequential = 'sequential';
}
