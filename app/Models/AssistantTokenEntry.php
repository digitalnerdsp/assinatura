<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lançamento da carteira de tokens: concessão (plano, compra, ajuste positivo), uso ou ajuste negativo. */
class AssistantTokenEntry extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tokens' => 'integer', 'input_tokens' => 'integer', 'cached_tokens' => 'integer', 'output_tokens' => 'integer', 'expires_on' => 'date'];
    }

    /** Usos que saíram desta concessão. */
    public function consumed(): HasMany
    {
        return $this->hasMany(self::class, 'source_id');
    }
}
