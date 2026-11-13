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
        Schema::table('folders', function (Blueprint $table) {
            $table->foreignUuid('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->dropUnique(['tenant_id', 'name']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Nome único dentro da mesma pasta pai (a raiz conta como um pai próprio), sem diferenciar maiúsculas.
            DB::statement("CREATE UNIQUE INDEX folders_name_per_parent_unique ON folders (tenant_id, (COALESCE(parent_id, '00000000-0000-0000-0000-000000000000'::uuid)), (lower(name)))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS folders_name_per_parent_unique');
        }

        Schema::table('folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->unique(['tenant_id', 'name']);
        });
    }
};
