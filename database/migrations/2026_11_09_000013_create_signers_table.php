<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('envelope_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('cpf', 14)->nullable();
            $table->string('role')->default('signer'); // signer | witness | approver
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('status')->default('pending');

            $table->char('token_hash', 64)->nullable()->unique();
            $table->char('otp_hash', 64)->nullable();
            $table->timestampTz('otp_expires_at')->nullable();
            $table->unsignedSmallInteger('otp_attempts')->default(0);
            $table->timestampTz('otp_verified_at')->nullable();

            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->text('refused_reason')->nullable();
            $table->timestampsTz();

            $table->index(['envelope_id', 'position']);
        });

        Schema::create('signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('signer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type'); // drawn | typed
            $table->text('data');   // PNG base64 ou nome digitado
            $table->char('data_sha256', 64);
            $table->char('document_sha256', 64); // hash do documento no momento da assinatura
            $table->string('level')->default('simple');
            $table->timestampTz('signed_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
        Schema::dropIfExists('signers');
    }
};
