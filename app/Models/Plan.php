<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Plano comercial. Limites ausentes = ilimitado; a lista de recursos cresce sem migração (JSON). */
class Plan extends Model
{
    use HasUuids;

    /** Recursos que um plano liga ou desliga. */
    public const FEATURES = ['whatsapp', 'api', 'webhooks', 'templates', 'positioning', 'ordered_signers', 'selfie', 'timestamp', 'reports', 'white_label'];

    public const LIMITS = ['documents', 'users', 'storage_mb', 'max_pdf_mb'];

    protected $guarded = [];

    protected $attributes = ['limits' => '[]', 'trial' => '[]', 'consumption' => '[]', 'features' => '[]'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean', 'is_public' => 'boolean', 'is_default' => 'boolean',
            'limits' => 'array', 'trial' => 'array', 'consumption' => 'array', 'features' => 'array',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isLegacy(): bool
    {
        return $this->slug === 'legado';
    }
}
