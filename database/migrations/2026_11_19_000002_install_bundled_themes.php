<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Instala os temas que vêm com o sistema e ativa o padrão na primeira vez. Nos testes não roda
 * (eles exercitam a reserva e os temas de teste); em produção roda no deploy junto das migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        Artisan::call('themes:install-bundled');
    }

    public function down(): void
    {
    }
};
