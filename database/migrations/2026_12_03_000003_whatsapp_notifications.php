<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Avisos enviados ao usuário pelo WhatsApp (um por chave de deduplicação) e a preferência de cada usuário. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('template_key', 40);
            $table->string('dedupe_key', 120)->unique(); // o mesmo aviso nunca sai duas vezes
            $table->string('status', 10)->default('queued'); // queued | sent | failed
            $table->string('reason', 190)->nullable();
            $table->string('wa_message_id', 120)->nullable();
            $table->json('params')->nullable(); // só enquanto o envio não termina
            $table->timestampsTz();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('whatsapp_notifications')->nullable(); // nulo = todos os avisos; lista = só essas situações
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('whatsapp_notifications'));
        Schema::dropIfExists('whatsapp_notifications');
    }
};
