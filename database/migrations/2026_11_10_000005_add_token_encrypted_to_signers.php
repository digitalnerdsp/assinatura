<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signers', function (Blueprint $table) {
            // Link do signatário cifrado (APP_KEY), para o painel poder exibir/copiar/reenviar.
            $table->text('token_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('signers', function (Blueprint $table) {
            $table->dropColumn('token_encrypted');
        });
    }
};
