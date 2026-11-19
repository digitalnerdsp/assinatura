<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Mensagem recebida do WhatsApp. O id da mensagem é único: a Meta repete notificações e cada uma é processada uma vez. */
class WhatsAppInbound extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_inbounds';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
