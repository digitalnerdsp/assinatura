<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Aviso de status com preço que a Meta mandou e que ainda não pôde ser cobrado de uma empresa (número sem vínculo ou preço não cadastrado). */
class WhatsAppStatusEvent extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_status_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['billable' => 'boolean', 'occurred_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
