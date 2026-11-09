<?php

declare(strict_types=1);

namespace App\Enums;

enum EnvelopeStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case InProgress = 'in_progress';
    case Sealing = 'sealing';
    case Completed = 'completed';
    case Refused = 'refused';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isOpenForSigning(): bool
    {
        return in_array($this, [self::Sent, self::InProgress], true);
    }
}
