<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Enums\SigningOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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
            'require_otp' => 'boolean',
            'require_selfie' => 'boolean',
            'require_document' => 'boolean',
            'require_geolocation' => 'boolean',
            'notify_channels' => 'array',
            'metadata' => 'array',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function signatureFields(): HasMany
    {
        return $this->hasMany(SignatureField::class);
    }

    /** Canais de aviso escolhidos (padrão: só e-mail). @return list<string> */
    public function channels(): array
    {
        return $this->notify_channels ?: ['email'];
    }

    /**
     * Signatários que podem assinar agora: todos os pendentes (paralelo) ou o grupo de menor posição
     * ainda não concluído (sequencial; mesma posição = assinam juntos).
     *
     * @return Collection<int,Signer>
     */
    public function activeSigners(): Collection
    {
        $open = $this->signers()->where('role', 'signer')
            ->whereNotIn('status', [SignerStatus::Signed->value, SignerStatus::Refused->value])
            ->get();

        if ($this->signing_order !== SigningOrder::Sequential || $open->isEmpty()) {
            return $open;
        }

        $first = $open->min('position');

        return $open->where('position', $first)->values();
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
