<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->unsignedBigInteger('final_size')->nullable(); // tamanho do PDF selado (conciliação do armazenamento)
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestampTz('warned_80_at')->nullable();  // aviso de 80% já enviado neste período
            $table->timestampTz('warned_100_at')->nullable(); // aviso de 100% já enviado neste período
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn(['warned_80_at', 'warned_100_at']));
        Schema::table('envelopes', fn (Blueprint $t) => $t->dropColumn('final_size'));
    }
};
