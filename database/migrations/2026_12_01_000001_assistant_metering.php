<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Carteira de tokens do assistente (pacote do plano, compras, uso e ajustes) e registro das mensagens da Meta por empresa. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_token_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // plan | purchase | usage | adjust
            $table->integer('tokens'); // com sinal: concessões positivas, uso negativo
            $table->uuid('source_id')->nullable(); // no uso: de qual concessão saiu (nulo = excesso ou ajuste negativo)
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('conversation_id', 64)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('period', 7)->nullable(); // YYYY-MM (pacote do plano)
            $table->string('note', 190)->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'kind']);
            $table->index('source_id');
        });
        // um pacote do plano por empresa e período, mesmo com duas execuções ao mesmo tempo
        DB::statement("CREATE UNIQUE INDEX assistant_plan_package_unique ON assistant_token_entries (tenant_id, period) WHERE kind = 'plan'");

        Schema::create('meta_message_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('wa_message_id', 120)->unique();
            $table->string('category', 32);
            $table->boolean('billable')->default(false);
            $table->decimal('meta_amount', 12, 6)->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->decimal('charged_amount', 12, 6)->default(0);
            $table->decimal('markup_percent', 6, 2)->default(0);
            $table->string('period', 7);
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_message_usages');
        Schema::dropIfExists('assistant_token_entries');
    }
};
