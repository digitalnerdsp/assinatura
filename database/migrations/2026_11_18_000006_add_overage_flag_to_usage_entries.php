<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_entries', function (Blueprint $table) {
            $table->boolean('overage')->default(false); // envio acima da cota; a devolução leva a mesma marca (soma dos deltas = excedente em uso)
        });
    }

    public function down(): void
    {
        Schema::table('usage_entries', fn (Blueprint $t) => $t->dropColumn('overage'));
    }
};
