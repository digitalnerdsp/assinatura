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
        Schema::create('evidence_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('signer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type');
            $table->jsonb('payload');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->unique(['envelope_id', 'sequence']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Trilha append-only: nenhuma atualização ou exclusão direta é permitida.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION evidence_events_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'evidence_events é append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER evidence_events_no_update
                BEFORE UPDATE ON evidence_events
                FOR EACH ROW EXECUTE FUNCTION evidence_events_immutable();

                CREATE TRIGGER evidence_events_no_delete
                BEFORE DELETE ON evidence_events
                FOR EACH ROW EXECUTE FUNCTION evidence_events_immutable();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS evidence_events_no_update ON evidence_events');
            DB::unprepared('DROP TRIGGER IF EXISTS evidence_events_no_delete ON evidence_events');
            DB::unprepared('DROP FUNCTION IF EXISTS evidence_events_immutable()');
        }

        Schema::dropIfExists('evidence_events');
    }
};
