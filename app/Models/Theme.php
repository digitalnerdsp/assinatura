<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Tema instalado (aparência global). Os arquivos ficam em config('themes.path')/<slug>/<versão>. */
class Theme extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['bundled' => 'boolean', 'contract' => 'integer'];
    }

    public function directory(): string
    {
        return rtrim((string) config('themes.path'), '/\\').DIRECTORY_SEPARATOR.$this->slug.DIRECTORY_SEPARATOR.$this->version;
    }

    public function isActive(): bool
    {
        return Setting::get('active_theme') === $this->getKey();
    }
}
