<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lacuna de um modelo em PDF: onde o texto preenchido por quem envia é escrito. */
class TemplateField extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float', 'width' => 'float', 'height' => 'float', 'page' => 'integer', 'position' => 'integer', 'multiline' => 'boolean', 'required' => 'boolean'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }
}
