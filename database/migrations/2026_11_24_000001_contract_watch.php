<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Vigência do contrato (data de vencimento e aviso) e o registro dos avisos já enviados. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->date('contract_expires_on')->nullable();                // nulo = sem monitoramento
            $table->unsignedSmallInteger('contract_alert_days')->nullable(); // 7 | 15 | 30 | 60 | 90
            $table->json('contract_alert_emails')->nullable();               // até 5 e-mails
            $table->index(['tenant_id', 'contract_expires_on']);
        });

        Schema::create('contract_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('envelope_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);              // before | due
            $table->date('due_on');                  // a data de vencimento a que o aviso se refere
            $table->text('sent_to');                 // e-mails que receberam
            $table->timestampTz('sent_at');
            $table->timestampsTz();
            $table->unique(['envelope_id', 'kind', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_alerts');
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'contract_expires_on']);
            $table->dropColumn(['contract_expires_on', 'contract_alert_days', 'contract_alert_emails']);
        });
    }
};
