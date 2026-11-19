<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Catálogo de pacotes adicionais de tokens e os pedidos das empresas (liberados à mão enquanto não há gateway). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->unsignedInteger('tokens'); // tokens equivalentes
            $table->unsignedInteger('price_cents'); // em reais
            $table->unsignedSmallInteger('validity_months')->default(12);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('assistant_package_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('package_id')->nullable()->constrained('assistant_packages')->nullOnDelete();
            // o que foi pedido, congelado na hora do pedido (mudar o pacote depois não muda o pedido)
            $table->string('name', 80);
            $table->unsignedInteger('tokens');
            $table->unsignedInteger('price_cents');
            $table->unsignedSmallInteger('validity_months');
            $table->string('status', 10)->default('pending'); // pending | approved | rejected | canceled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by', 190)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('note', 190)->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_package_orders');
        Schema::dropIfExists('assistant_packages');
    }
};
