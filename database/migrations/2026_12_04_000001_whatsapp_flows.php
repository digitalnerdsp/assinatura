<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Conversa guiada para enviar um documento pelo WhatsApp: um fluxo ativo por usuário, que expira em 30 minutos. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('step', 20); // title | signers | review | sending
            $table->json('data')->nullable(); // envelope_id, filename, título, signatários digitados
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flows');
    }
};
