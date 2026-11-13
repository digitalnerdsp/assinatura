<?php

declare(strict_types=1);

use App\Plans\PlanService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 12)->default('active');
            $table->boolean('billing_exempt')->default(false);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->jsonb('overrides')->default('[]');      // limits, features, consumption, price_cents
            $table->integer('credits_balance')->default(0); // pacote que não renova
            $table->integer('quota_used')->default(0);      // cota do período
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('trialing', 'active', 'blocked', 'canceled'))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_nonneg_check CHECK (credits_balance >= 0 AND quota_used >= 0)');

        // Extrato de uso: só se acrescenta, nunca se altera.
        Schema::create('usage_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('envelope_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10);
            $table->integer('delta');
            $table->integer('balance_after');
            $table->string('note', 255)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->nullable();
            $table->index(['tenant_id', 'occurred_at']);
        });
        DB::statement("ALTER TABLE usage_entries ADD CONSTRAINT usage_entries_kind_check CHECK (kind IN ('quota', 'credit', 'refund', 'adjust'))");

        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_bytes')->default(0);
        });

        // Quem já usa o sistema fica no plano "Legado": ilimitado, sem cobrança, nada quebra.
        app(PlanService::class)->backfillLegacy();
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('storage_bytes'));
        Schema::dropIfExists('usage_entries');
        Schema::dropIfExists('subscriptions');
    }
};
