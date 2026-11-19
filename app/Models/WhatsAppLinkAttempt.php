<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tentativas de código erradas de um número (5 erros bloqueiam o número por 1 hora). */
class WhatsAppLinkAttempt extends Model
{
    protected $table = 'whatsapp_link_attempts';

    protected $primaryKey = 'phone';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['blocked_until' => 'datetime'];
    }
}
