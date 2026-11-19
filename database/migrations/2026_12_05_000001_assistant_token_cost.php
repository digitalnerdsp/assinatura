<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Custo real de cada resposta da IA, com o preço e o câmbio da época (o histórico nunca muda quando o preço muda). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_token_entries', function (Blueprint $table) {
            $table->decimal('cost_usd', 14, 8)->nullable();
            $table->decimal('fx', 10, 4)->nullable();
            $table->decimal('cost_brl', 14, 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_token_entries', fn (Blueprint $t) => $t->dropColumn(['cost_usd', 'fx', 'cost_brl']));
    }
};
