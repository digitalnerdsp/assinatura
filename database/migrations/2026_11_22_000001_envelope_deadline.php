<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Data limite escolhida no assistente: vale até o fim do dia (Brasília), não "N dias a partir do envio". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->timestampTz('deadline_at')->nullable()->after('validity_days');
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropColumn('deadline_at');
        });
    }
};
