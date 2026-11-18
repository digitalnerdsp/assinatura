<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/** Modelo de documento: um PDF com lacunas (template_fields) que quem envia preenche a cada uso. */
class DocumentTemplate extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['page_count' => 'integer', 'pdf_size' => 'integer'];
    }

    /** Lacunas na ordem de leitura (página, de cima para baixo, da esquerda para a direita). */
    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class, 'template_id')->orderBy('position');
    }

    public function pdfContents(): string
    {
        return (string) Storage::disk('local')->get((string) $this->pdf_path);
    }
}
