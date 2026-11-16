<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignatureField extends Model
{
    use HasUuids;

    /** Tipos de campo que o assistente aceita (assinatura; visto e texto entram na Tarefa 8). */
    public const TYPES = ['signature'];

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
