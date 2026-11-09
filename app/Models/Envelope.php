<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Envelope extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => EnvelopeStatus::class,
            'signing_order' => SigningOrder::class,
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'sealed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function signers(): HasMany
    {
        return $this->hasMany(Signer::class)->orderBy('position');
    }

    public function evidenceEvents(): HasMany
    {
        return $this->hasMany(EvidenceEvent::class)->orderBy('sequence');
    }
}
