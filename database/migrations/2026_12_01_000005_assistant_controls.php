<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Controles do assistente: liga/desliga por empresa e por usuário, e quais grupos de ações a empresa permite. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('assistant_enabled')->default(true);
            $table->json('assistant_tools')->nullable(); // nulo = todas as ações; lista = só esses grupos
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('assistant_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('assistant_enabled'));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['assistant_enabled', 'assistant_tools']));
    }
};
