<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Aviso enviado (ou tentado) ao WhatsApp de um usuário. */
class WhatsAppNotification extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_notifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['params' => 'array'];
    }
}
