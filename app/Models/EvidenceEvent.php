<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Somente leitura: o banco impede UPDATE/DELETE por trigger. */
class EvidenceEvent extends Model
{
    public $timestamps = false;

    /** Inclui o fuso: sem ele o Postgres reinterpreta o horário UTC no fuso da sessão. */
    protected $dateFormat = 'Y-m-d H:i:sO';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
