<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Contato da empresa (agenda de signatários). */
class Contact extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
