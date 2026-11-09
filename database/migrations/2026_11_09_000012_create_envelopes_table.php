<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('document_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('status')->default('draft');
            $table->string('signing_order')->default('parallel');
            $table->string('required_level')->default('simple'); // simple | advanced | qualified
            $table->string('verification_code', 32)->unique();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            // resultado da selagem
            $table->string('final_path')->nullable();
            $table->char('final_sha256', 64)->nullable();
            $table->string('sealed_provider')->nullable();
            $table->string('sealed_cert_serial')->nullable();
            $table->timestampTz('sealed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelopes');
    }
};
