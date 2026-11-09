<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signer_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('signer_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // selfie | document
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->string('source', 20)->default('camera'); // camera | upload
            $table->timestampsTz();

            $table->unique(['signer_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signer_attachments');
    }
};
