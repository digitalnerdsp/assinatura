<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Conversas do assistente: texto das mensagens e resultados das ferramentas (nunca o conteúdo ou o arquivo de PDFs). Guardadas por 90 dias. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('last_message_at');
            $table->timestampsTz();
            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role', 12); // user | assistant | tool
            $table->text('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id', 80)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['conversation_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
