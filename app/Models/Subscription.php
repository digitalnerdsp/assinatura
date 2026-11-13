<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Assinatura da empresa: plano atual, situação, saldo e sobrescritas (plano sob medida). */
class Subscription extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $attributes = ['overrides' => '[]'];

    protected function casts(): array
    {
        return [
            'billing_exempt' => 'boolean', 'overrides' => 'array',
            'trial_ends_at' => 'datetime', 'period_start' => 'datetime', 'period_end' => 'datetime',
            'credits_balance' => 'integer', 'quota_used' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
