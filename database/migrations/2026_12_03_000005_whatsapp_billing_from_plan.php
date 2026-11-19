<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O modo de cobrança dos avisos (pré-pago ou pós-pago com limite) passa a vir do plano (`limits.wa_credit_limit_cents`),
 * com sobrescrita por empresa na assinatura. As colunas por empresa saem.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Quem já tinha pós-pago com limite definido por empresa o mantém, agora como sobrescrita da assinatura.
        foreach (DB::table('tenants')->where('wa_billing_mode', 'postpaid')->get(['id', 'wa_credit_limit']) as $t) {
            $sub = DB::table('subscriptions')->where('tenant_id', $t->id)->first(['id', 'overrides']);
            if ($sub === null) {
                continue;
            }
            $o = json_decode((string) $sub->overrides, true) ?: [];
            $o['limits']['wa_credit_limit_cents'] = (int) round(((float) $t->wa_credit_limit) * 100);
            DB::table('subscriptions')->where('id', $sub->id)->update(['overrides' => json_encode($o)]);
        }

        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['wa_billing_mode', 'wa_credit_limit']));
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('wa_billing_mode', 10)->default('prepaid');
            $table->decimal('wa_credit_limit', 12, 2)->nullable();
        });
    }
};
