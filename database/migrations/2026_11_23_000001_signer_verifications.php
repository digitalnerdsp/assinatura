<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificações de identidade por signatário (cada um com as suas), somadas às do documento inteiro que a API ainda usa.
 * E o remetente passa a ser "opcional" por padrão (escolha em cada documento) para empresas sem configuração salva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signers', function (Blueprint $table) {
            $table->boolean('require_otp')->default(false);
            $table->boolean('require_selfie')->default(false);
            $table->boolean('require_document')->default(false);
            $table->boolean('require_geolocation')->default(false);
        });

        Schema::table('tenant_signing_settings', function (Blueprint $table) {
            $table->string('sender_policy', 20)->default('optional')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_signing_settings', function (Blueprint $table) {
            $table->string('sender_policy', 20)->default('always')->change();
        });

        Schema::table('signers', function (Blueprint $table) {
            $table->dropColumn(['require_otp', 'require_selfie', 'require_document', 'require_geolocation']);
        });
    }
};
