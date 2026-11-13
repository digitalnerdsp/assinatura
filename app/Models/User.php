<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'tenant_id', 'role'])]
#[Hidden(['password', 'remember_token', 'signature_data', 'invite_token_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Tem uma "Minha assinatura" salva (desenhada ou digitada) para assinar como remetente. */
    public function hasSignature(): bool
    {
        return in_array($this->signature_type, ['drawn', 'typed'], true) && trim((string) $this->signature_data) !== '';
    }

    /** Foto enviada pelo usuário; sem foto, um avatar padrão estável (escolhido pelo e-mail). */
    public function avatarUrl(): string
    {
        if ($this->avatar_path) {
            return route('profile.photo', ['user' => $this->getKey(), 'v' => substr(md5($this->avatar_path), 0, 8)]);
        }

        return asset('assets/images/avatars/avatar-'.((crc32($this->email) % 5) + 1).'.png');
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /** Convidado que ainda não criou a senha. */
    public function isPendingInvite(): bool
    {
        return $this->invite_token_hash !== null;
    }

    public function isSuperAdmin(): bool
    {
        return in_array(mb_strtolower($this->email), config('platform.super_admins', []), true);
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'invite_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
