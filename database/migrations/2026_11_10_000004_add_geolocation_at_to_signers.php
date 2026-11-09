<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signers', function (Blueprint $table) {
            $table->timestampTz('geolocation_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('signers', function (Blueprint $table) {
            $table->dropColumn('geolocation_at');
        });
    }
};
