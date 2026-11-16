<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Colunas do assistente de criação de documento (rascunho por passos, recusa opcional, campos Visto e Texto). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->unsignedTinyInteger('wizard_step')->nullable()->after('status'); // último passo concluído; nulo = criado fora do assistente
            $table->boolean('allow_refuse')->default(true)->after('wizard_step');
        });

        Schema::table('signers', function (Blueprint $table) {
            $table->string('email')->nullable()->change(); // o rascunho começa só com o nome
        });

        Schema::table('signature_fields', function (Blueprint $table) {
            $table->string('type', 16)->default('signature')->after('signer_id'); // signature | initials | text
            $table->text('value')->nullable()->after('height'); // texto digitado pelo signatário (tipo text)
        });

        Schema::table('signatures', function (Blueprint $table) {
            $table->string('initials_type', 16)->nullable(); // drawn | typed
            $table->text('initials_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table) {
            $table->dropColumn(['initials_type', 'initials_data']);
        });
        Schema::table('signature_fields', function (Blueprint $table) {
            $table->dropColumn(['type', 'value']);
        });
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropColumn(['wizard_step', 'allow_refuse']);
        });
    }
};
