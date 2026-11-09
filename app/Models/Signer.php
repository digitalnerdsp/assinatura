<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SignerStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Signer extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['token_hash', 'otp_hash', 'token_encrypted'];

    /** Link público de assinatura, se o token ainda estiver disponível (envelopes antigos não têm). */
    public function signUrl(): ?string
    {
        if ($this->token_encrypted === null) {
            return null;
        }

        try {
            return route('sign.show', ['token' => \Illuminate\Support\Facades\Crypt::decryptString($this->token_encrypted)]);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return null; // APP_KEY mudou
        }
    }

    protected function casts(): array
    {
        return [
            'status' => SignerStatus::class,
            'otp_expires_at' => 'datetime',
            'otp_verified_at' => 'datetime',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'geolocation_at' => 'datetime',
        ];
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function attachments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SignerAttachment::class);
    }

    public function attachment(string $kind): ?SignerAttachment
    {
        return $this->attachments->firstWhere('kind', $kind);
    }

    public function signature(): HasOne
    {
        return $this->hasOne(Signature::class);
    }
}
