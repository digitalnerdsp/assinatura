<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestampTz('suspended_at')->nullable();
            $table->string('plan')->default('free');
            $table->unsignedInteger('monthly_envelope_limit')->nullable(); // null = ilimitado
            $table->text('admin_notes')->nullable();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'plan', 'monthly_envelope_limit', 'admin_notes']);
        });
    }
};
