<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->longText('body'); // HTML simples com {{variavel}}
            $table->timestampsTz();
            $table->index('tenant_id');
        });

        // Onde a assinatura de cada signatário aparece no PDF (frações da página: 0..1, origem no topo-esquerda).
        Schema::create('signature_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('signer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page');
            $table->decimal('x', 6, 5);
            $table->decimal('y', 6, 5);
            $table->decimal('width', 6, 5)->default(0.28);
            $table->decimal('height', 6, 5)->default(0.07);
            $table->timestampsTz();
            $table->index('envelope_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_fields');
        Schema::dropIfExists('document_templates');
    }
};
