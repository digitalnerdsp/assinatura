<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Saldo em dinheiro das mensagens do WhatsApp (avisos): recarga, ajuste, uso e quitação; modo pré-pago ou pós-pago com limite. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('wa_billing_mode', 10)->default('prepaid'); // prepaid | postpaid
            $table->decimal('wa_credit_limit', 12, 2)->nullable(); // teto do pós-pago
        });

        Schema::create('whatsapp_credit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // topup | adjust | usage | settle
            $table->decimal('amount', 14, 6); // com sinal: recarga e quitação somam, uso subtrai
            $table->string('currency', 3)->default('BRL');
            $table->string('period', 7)->nullable(); // AAAA-MM do uso
            $table->uuid('source_id')->nullable()->unique(); // o uso da Meta que originou o débito (um débito por mensagem)
            $table->string('note', 190)->nullable();
            $table->string('created_by', 190)->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_credit_entries');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['wa_billing_mode', 'wa_credit_limit']));
    }
};
