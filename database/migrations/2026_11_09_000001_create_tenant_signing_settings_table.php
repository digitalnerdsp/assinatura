<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_signing_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider')->default('platform'); // platform | tenant_cloud
            $table->text('credential_ref')->nullable();      // criptografado pelo cast
            $table->string('fallback_policy')->default('block'); // block | use_platform
            $table->timestampTz('certificate_valid_to')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_signing_settings');
    }
};
