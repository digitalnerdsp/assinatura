<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Preço de um plano por período (centavos inteiros, BRL). */
class PlanPrice extends Model
{
    use HasUuids;

    public const PERIODS = ['month' => 'Mensal', 'quarter' => 'Trimestral', 'semester' => 'Semestral', 'year' => 'Anual'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'active' => 'boolean'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
