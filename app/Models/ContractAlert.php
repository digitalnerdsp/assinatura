<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aviso de vencimento de contrato já enviado (um por documento, tipo e data de vencimento). */
class ContractAlert extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'sent_at' => 'datetime'];
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }
}
