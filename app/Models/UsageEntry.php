<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Lançamento do extrato de uso (só se acrescenta). */
class UsageEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'delta' => 'integer', 'balance_after' => 'integer', 'overage' => 'boolean'];
    }
}
