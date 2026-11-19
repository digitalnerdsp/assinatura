<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Mensagem da conversa no formato de chat: user, assistant (com ou sem pedidos de ferramenta) e tool. */
class AssistantMessage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tool_calls' => 'array', 'created_at' => 'datetime'];
    }
}
