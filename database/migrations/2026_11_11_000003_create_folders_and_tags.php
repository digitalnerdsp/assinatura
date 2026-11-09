<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 20)->default('primary');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('envelope_tag', function (Blueprint $table) {
            $table->foreignUuid('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['envelope_id', 'tag_id']);
        });

        Schema::table('envelopes', function (Blueprint $table) {
            $table->foreignUuid('folder_id')->nullable()->constrained('folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });
        Schema::dropIfExists('envelope_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('folders');
    }
};
