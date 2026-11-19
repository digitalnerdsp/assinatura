<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Códigos de vínculo do número do WhatsApp (só o hash) e tentativas erradas por número. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_link_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
            $table->index(['used_at', 'expires_at']);
        });

        Schema::create('whatsapp_link_attempts', function (Blueprint $table) {
            $table->string('phone', 20)->primary();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('blocked_until')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_link_attempts');
        Schema::dropIfExists('whatsapp_link_codes');
    }
};
