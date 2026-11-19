<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Mensagem do WhatsApp que a Meta informou (com categoria e preço), por empresa. Guarda o valor original e o cobrado do cliente. */
class MetaMessageUsage extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['billable' => 'boolean', 'occurred_at' => 'datetime'];
    }
}
