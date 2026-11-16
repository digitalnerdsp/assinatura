<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Temas do sistema 1.0.1: o item "Documentos" do menu abre a lista de documentos. Instala a versão nova,
 * ativa no lugar da antiga (se ela estava ativa) e apaga as versões antigas. Nos testes não roda.
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
