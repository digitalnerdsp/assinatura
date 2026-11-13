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

    public const FEATURE_LABELS = ['whatsapp' => 'WhatsApp e SMS', 'api' => 'API', 'webhooks' => 'Webhooks', 'templates' => 'Modelos', 'positioning' => 'Posicionamento da assinatura', 'ordered_signers' => 'Signatários em ordem', 'selfie' => 'Selfie e foto do documento', 'timestamp' => 'Carimbo de tempo', 'reports' => 'Relatórios', 'white_label' => 'Marca própria (logo e cores)'];

    /** Recursos que o sistema já barra de verdade quando desligados; os demais ainda são só informativos. */
    public const FEATURES_ENFORCED = ['api', 'webhooks', 'templates', 'reports', 'white_label'];

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
