<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Configurações globais da plataforma (chave/valor). */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::query()->whereKey($key)->value('value') ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Link da Central de ajuda configurado pelo administrador (null = não mostrar). */
    public static function helpUrl(): ?string
    {
        $url = trim((string) self::get('help_url', ''));

        return $url === '' ? null : $url;
    }

    public static function registrationOpen(): bool
    {
        return self::get('registration_open', '1') === '1';
    }
}
