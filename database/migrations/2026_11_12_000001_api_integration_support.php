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
            $table->string('external_id', 120)->nullable();  // id do envelope no sistema do cliente
            $table->json('metadata')->nullable();            // dados livres do cliente (devolvidos na API e nos webhooks)
            $table->unique(['tenant_id', 'external_id']);
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->boolean('read_only')->default(false);
        });

        // Idempotência: repetir um POST com o mesmo Idempotency-Key devolve a resposta original em vez de duplicar.
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->longText('response_body');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
        Schema::table('api_keys', fn (Blueprint $t) => $t->dropColumn('read_only'));
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_id']);
            $table->dropColumn(['external_id', 'metadata']);
        });
    }
};
