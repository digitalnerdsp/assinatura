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
            $table->string('brand_name', 60)->nullable();
            $table->string('brand_logo_path')->nullable();
            $table->string('brand_primary', 7)->nullable(); // #rrggbb, já com contraste garantido
            $table->string('brand_accent', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['brand_name', 'brand_logo_path', 'brand_primary', 'brand_accent']));
    }
};
