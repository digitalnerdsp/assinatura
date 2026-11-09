<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** Variáveis {{nome}} usadas no corpo, na ordem em que aparecem. @return list<string> */
    public function variables(): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $this->body, $m);

        return array_values(array_unique($m[1]));
    }
}
