<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Conversa guiada em andamento de um usuário (envio de documento pelo WhatsApp). */
class WhatsAppFlow extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_flows';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'expires_at' => 'datetime'];
    }
}
