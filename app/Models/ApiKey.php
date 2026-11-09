<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return array{0:ApiKey,1:string} modelo + chave em texto puro (mostrar uma única vez) */
    public static function issue(Tenant $tenant, string $name): array
    {
        $plain = 'sk_live_'.Str::random(40);

        $key = self::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => $name,
            'prefix' => substr($plain, 0, 12),
            'key_hash' => hash('sha256', $plain),
        ]);

        return [$key, $plain];
    }
}
