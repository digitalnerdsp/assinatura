<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_public')->default(true);   // aparece na página de preços
            $table->boolean('is_default')->default(false); // plano das empresas novas
            $table->unsignedInteger('position')->default(0);
            $table->jsonb('limits')->default('[]');        // documents, users, storage_mb, max_pdf_mb (ausente = ilimitado)
            $table->jsonb('trial')->default('[]');         // days, requires_payment, on_end, limits
            $table->jsonb('consumption')->default('[]');   // when, refund_unsigned, balance, quota_amount, on_empty, overage_cents, cap
            $table->jsonb('features')->default('[]');      // booleanos por recurso
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX plans_single_default ON plans (is_default) WHERE is_default');

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained()->cascadeOnDelete();
            $table->string('period', 10);
            $table->unsignedInteger('amount_cents');
            $table->string('discount_label', 60)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['plan_id', 'period']);
        });
        DB::statement("ALTER TABLE plan_prices ADD CONSTRAINT plan_prices_period_check CHECK (period IN ('month', 'quarter', 'semester', 'year'))");
        DB::statement('ALTER TABLE plan_prices ADD CONSTRAINT plan_prices_amount_check CHECK (amount_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
