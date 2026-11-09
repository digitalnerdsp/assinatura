<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Signature extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(Signer::class);
    }
}
