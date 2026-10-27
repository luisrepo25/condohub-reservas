<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotency-Key de la cancelación de una reserva (O3, R7).
 * Fuente: database/sql/003_idempotencia_cancelacion.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/003_idempotencia_cancelacion.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE reserva DROP CONSTRAINT IF EXISTS ck_reserva_clave_cancelacion;
            ALTER TABLE reserva DROP COLUMN IF EXISTS cancelacion_idempotency_key;
        SQL);
    }
};
