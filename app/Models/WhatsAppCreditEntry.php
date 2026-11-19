<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Lançamento do saldo em dinheiro do WhatsApp de uma empresa. */
class WhatsAppCreditEntry extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_credit_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }
}
