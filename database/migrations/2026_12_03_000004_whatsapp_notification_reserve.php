<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Reserva do custo estimado de cada aviso até a Meta informar a cobrança real (evita gastar mais que o saldo/limite). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_notifications', function (Blueprint $table) {
            $table->decimal('reserved_amount', 12, 6)->default(0);
            $table->index(['tenant_id', 'status']);
            $table->index('wa_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_notifications', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropIndex(['wa_message_id']);
            $table->dropColumn('reserved_amount');
        });
    }
};
