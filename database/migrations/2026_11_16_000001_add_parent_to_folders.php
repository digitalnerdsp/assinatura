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
            // A regra antiga diferenciava maiúsculas ("Contratos" e "contratos" podiam coexistir). O índice novo não
            // diferencia: o mais antigo mantém o nome e os repetidos ganham um sufixo " (2)", " (3)"...
            for ($pass = 0; $pass < 3; $pass++) {
                DB::statement("UPDATE folders f SET name = left(f.name, 112) || ' (' || d.rn || ')' FROM (SELECT id, row_number() OVER (PARTITION BY tenant_id, lower(name) ORDER BY created_at, id) AS rn FROM folders) d WHERE f.id = d.id AND d.rn > 1");
                if (! DB::selectOne('SELECT 1 AS dup FROM folders GROUP BY tenant_id, lower(name) HAVING count(*) > 1 LIMIT 1')) {
                    break;
                }
            }

            // Nome único dentro da mesma pasta pai (a raiz conta como um pai próprio), sem diferenciar maiúsculas.
            DB::statement("CREATE UNIQUE INDEX folders_name_per_parent_unique ON folders (tenant_id, (COALESCE(parent_id, '00000000-0000-0000-0000-000000000000'::uuid)), (lower(name)))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS folders_name_per_parent_unique');
        }

        if (DB::getDriverName() === 'pgsql') {
            // Voltando ao esquema plano, subpastas de mesmo nome (permitidas em pais diferentes) colidiriam:
            // elas ganham um sufixo curto do id (o final, que é aleatório no UUID v7) para o nome continuar único por empresa.
            DB::statement("UPDATE folders SET name = left(name, 110) || ' (' || right(id::text, 8) || ')' WHERE parent_id IS NOT NULL");
        }

        Schema::table('folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->unique(['tenant_id', 'name']);
        });
    }
};
