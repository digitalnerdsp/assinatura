<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marca de "já começou a ser tratada": garante que uma mensagem recebida é processada no máximo uma vez. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_inbounds', function (Blueprint $table) {
            $table->timestampTz('processing_started_at')->nullable();
            $table->index(['processed_at', 'processing_started_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_inbounds', fn (Blueprint $t) => $t->dropColumn('processing_started_at'));
    }
};
