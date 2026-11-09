<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function signingSetting(): HasOne
    {
        return $this->hasOne(TenantSigningSetting::class);
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(Envelope::class);
    }
}
