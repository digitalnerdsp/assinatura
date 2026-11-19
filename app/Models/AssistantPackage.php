<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Pacote adicional de tokens à venda (preço em centavos de real). */
class AssistantPackage extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tokens' => 'integer', 'price_cents' => 'integer', 'validity_months' => 'integer', 'active' => 'boolean', 'position' => 'integer'];
    }
}
