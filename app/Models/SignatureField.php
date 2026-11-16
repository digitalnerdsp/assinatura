<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignatureField extends Model
{
    use HasUuids;

    /** Tipos de campo do assistente: assinatura, visto (rubrica do signatário) e texto (preenchido pelo signatário). */
    public const TYPES = ['signature', 'initials', 'text'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float', 'width' => 'float', 'height' => 'float', 'page' => 'integer'];
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(Signer::class);
    }
}
