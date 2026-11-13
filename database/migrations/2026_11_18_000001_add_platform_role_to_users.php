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
        Schema::table('users', function (Blueprint $table) {
            $table->string('platform_role', 20)->nullable(); // admin | super_admin (os "raiz" vêm de SUPER_ADMIN_EMAILS)
            $table->foreignId('platform_granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('platform_granted_at')->nullable();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_platform_role_check CHECK (platform_role IS NULL OR platform_role IN ('admin', 'super_admin'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_platform_role_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('platform_granted_by');
            $table->dropColumn(['platform_role', 'platform_granted_at']);
        });
    }
};
