<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime', 'assistant_enabled' => 'boolean', 'assistant_tools' => 'array'];
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** Empresa de um super administrador ativo da plataforma: não usa plano e não é cobrada (a plataforma absorve). */
    public function isPlatformOwned(): bool
    {
        return $this->users()->whereNull('deactivated_at')->whereNull('invite_token_hash')->get()->contains(fn (User $u) => $u->isSuperAdmin());
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function signingSetting(): HasOne
    {
        return $this->hasOne(TenantSigningSetting::class);
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(Envelope::class);
    }
}
