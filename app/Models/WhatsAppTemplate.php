<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Modelo de mensagem do WhatsApp de uma situação de aviso. */
class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
