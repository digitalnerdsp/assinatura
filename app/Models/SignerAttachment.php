<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignerAttachment extends Model
{
    use HasUuids;

    public const SELFIE = 'selfie';
    public const DOCUMENT = 'document';

    protected $guarded = [];

    public function signer(): BelongsTo
    {
        return $this->belongsTo(Signer::class);
    }
}
