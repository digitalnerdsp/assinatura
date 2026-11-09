<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folder extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function envelopes(): HasMany
    {
        return $this->hasMany(Envelope::class);
    }
}
