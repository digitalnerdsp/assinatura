<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $tenant_id
 * @property string $provider            platform | tenant_cloud
 * @property array<string,mixed>|null $credential_ref   criptografado; só referências, nunca a chave
 * @property string $fallback_policy     block | use_platform
 * @property \Illuminate\Support\Carbon|null $certificate_valid_to
 */
class TenantSigningSetting extends Model
{
    use HasUuids;

    public const FALLBACK_BLOCK = 'block';
    public const FALLBACK_USE_PLATFORM = 'use_platform';

    protected $guarded = [];

    protected $hidden = ['credential_ref'];

    protected function casts(): array
    {
        return [
            'credential_ref' => 'encrypted:array',
            'certificate_valid_to' => 'datetime',
            'delegation_accepted_at' => 'datetime',
        ];
    }
}
