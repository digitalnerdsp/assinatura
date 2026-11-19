<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Pedido de um pacote de tokens por uma empresa. Guarda o pacote como estava na hora do pedido. */
class AssistantPackageOrder extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tokens' => 'integer', 'price_cents' => 'integer', 'validity_months' => 'integer', 'decided_at' => 'datetime'];
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
