<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Arquivos acrescentados a um envio (o principal continua em envelopes.document_id). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('final_path')->nullable();
            $table->char('final_sha256', 64)->nullable();
            $table->unsignedBigInteger('final_size')->nullable();
            $table->timestampTz('sealed_at')->nullable();
            $table->timestampsTz();
            $table->index(['envelope_id', 'position']);
        });

        // nulo = campo do arquivo principal
        Schema::table('signature_fields', function (Blueprint $table) {
            $table->foreignUuid('envelope_document_id')->nullable()->constrained('envelope_documents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('signature_fields', function (Blueprint $table) {
            $table->dropConstrainedForeignId('envelope_document_id');
        });
        Schema::dropIfExists('envelope_documents');
    }
};
