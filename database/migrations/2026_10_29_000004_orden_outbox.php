<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Orden estable del outbox: secuencia de inserción para desempatar mensajes del mismo segundo (S4).
 * Fuente: database/sql/004_orden_outbox.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/004_orden_outbox.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS ix_outbox_pendiente;
            ALTER TABLE outbox DROP COLUMN IF EXISTS secuencia;
            CREATE INDEX ix_outbox_pendiente ON outbox (occurred_at) WHERE published_at IS NULL;
        SQL);
    }
};
