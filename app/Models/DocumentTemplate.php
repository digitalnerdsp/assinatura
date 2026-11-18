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

    /** LEGADO (modelo em HTML): sai na Tarefa 6 junto com o TemplateRenderer, quando web e API passarem a usar as lacunas. @return list<string> */
    public function variables(): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', (string) $this->body, $m);

        return array_values(array_unique($m[1]));
    }

    public function pdfContents(): string
    {
        return (string) Storage::disk('local')->get((string) $this->pdf_path);
    }
}
