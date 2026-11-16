<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 80);
            $table->string('name', 120);
            $table->string('version', 30);
            $table->unsignedSmallInteger('contract');
            $table->string('author', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 12)->default('installed'); // installed | error
            $table->text('error')->nullable();
            $table->boolean('bundled')->default(false);          // vem junto com o sistema (não remove)
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['slug', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
