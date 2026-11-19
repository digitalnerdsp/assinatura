<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Modelos de mensagem do WhatsApp (um por situação de aviso), enviados à Meta para aprovação. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('meta_name', 120);
            $table->string('language', 10)->default('pt_BR');
            $table->string('category', 20)->default('utility');
            $table->text('body');
            $table->boolean('enabled')->default(false);
            $table->string('status', 12)->default('rascunho'); // rascunho | enviado | aprovado | rejeitado | pausado
            $table->string('rejected_reason', 190)->nullable();
            $table->string('meta_template_id', 80)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
