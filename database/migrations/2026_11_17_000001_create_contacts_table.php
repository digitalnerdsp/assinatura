<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('email', 190);
            $table->string('phone', 20)->nullable();     // E.164
            $table->string('document', 14)->nullable(); // CPF ou CNPJ, só dígitos
            $table->string('company', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'name']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX contacts_tenant_email_unique ON contacts (tenant_id, (lower(email)))');
        } else {
            Schema::table('contacts', fn (Blueprint $t) => $t->unique(['tenant_id', 'email']));
        }

        Schema::create('contact_tag', function (Blueprint $table) {
            $table->foreignUuid('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['contact_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tag');
        Schema::dropIfExists('contacts');
    }
};
