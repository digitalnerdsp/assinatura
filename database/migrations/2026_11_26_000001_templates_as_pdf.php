<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modelos passam a ser um PDF com lacunas (em vez de HTML com {{variáveis}}). Os modelos em HTML já criados
 * são apagados (decisão do produto: ninguém os usou); documentos gerados antes ficam sem modelo de origem.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_templates')->delete();

        Schema::table('document_templates', function (Blueprint $table) {
            $table->longText('body')->nullable()->change();
            $table->string('pdf_path', 255)->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->unsignedBigInteger('pdf_size')->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
        });

        Schema::create('template_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')->constrained('document_templates')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label', 60);
            $table->unsignedSmallInteger('page');
            // Frações da página visível (0..1), origem no canto superior esquerdo — igual a signature_fields.
            $table->decimal('x', 6, 5);
            $table->decimal('y', 6, 5);
            $table->decimal('width', 6, 5);
            $table->decimal('height', 6, 5);
            $table->boolean('multiline')->default(false);
            $table->boolean('required')->default(true);
            $table->unsignedSmallInteger('position');
            $table->timestampsTz();
            $table->unique(['template_id', 'key']);
            $table->index(['template_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_fields');
        Schema::table('document_templates', fn (Blueprint $t) => $t->dropColumn(['pdf_path', 'pdf_sha256', 'pdf_size', 'page_count']));
    }
};
