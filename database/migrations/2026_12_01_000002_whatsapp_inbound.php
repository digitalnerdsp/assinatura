<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Mensagens recebidas do WhatsApp (sem duplicar), avisos de status/preço da Meta e o número vinculado ao usuário. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_inbounds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('wa_message_id', 120)->unique();
            $table->string('from_phone', 20);
            $table->string('type', 16);
            $table->json('payload'); // só o necessário: texto, id do botão/lista, id da mídia, tipo e nome do arquivo (nunca o arquivo)
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->index(['from_phone', 'received_at']);
            $table->index('created_at');
        });

        Schema::create('whatsapp_status_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('wa_message_id', 120)->unique();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_phone', 20);
            $table->string('status', 16)->nullable();
            $table->string('category', 32)->nullable();
            $table->boolean('billable')->default(false);
            $table->timestampTz('occurred_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->index(['processed_at', 'recipient_phone']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_phone', 20)->nullable()->unique();
            $table->timestampTz('whatsapp_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_phone', 'whatsapp_verified_at']);
        });
        Schema::dropIfExists('whatsapp_status_events');
        Schema::dropIfExists('whatsapp_inbounds');
    }
};
