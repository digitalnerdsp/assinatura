<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Tema Able 1.0.2: logotipo do Assine Já no menu lateral. Instala a versão nova, ativa no lugar da antiga
 * (se ela estava ativa) e apaga as versões antigas. Nos testes não roda.
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
