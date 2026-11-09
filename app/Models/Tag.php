<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasUuids;

    protected $guarded = [];

    public const COLORS = ['primary', 'success', 'danger', 'warning', 'info', 'secondary', 'dark'];

    public function envelopes(): BelongsToMany
    {
        return $this->belongsToMany(Envelope::class);
    }
}
