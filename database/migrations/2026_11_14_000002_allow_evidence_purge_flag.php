<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A trilha de evidências continua append-only para qualquer escrita normal. A única exceção é a limpeza
 * da lixeira (TrashService::purge), que apaga o envelope inteiro (e com ele a própria trilha) dentro de uma
 * transação que liga `app.evidence_purge` só por ela (SET LOCAL). Só envelopes nunca concluídos chegam lá.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION evidence_events_immutable() RETURNS trigger AS $$
            BEGIN
                IF current_setting('app.evidence_purge', true) = 'on' THEN
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'evidence_events é append-only';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION evidence_events_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'evidence_events é append-only';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
