<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Esquema de Reservas en SQL nativo: el Schema Builder de Laravel no soporta
 * EXCLUDE USING gist (R2), columnas generadas tstzrange ni triggers (R8).
 * Fuente: database/sql/001_esquema_reservas.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/001_esquema_reservas.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS evento_procesado, outbox, saga_paso, saga_instancia,
                                 invitado_reserva, reserva, area_comun, parametro CASCADE;
            DROP FUNCTION IF EXISTS fn_reserva_transicion();
            DROP TYPE IF EXISTS estado_paso, estado_saga, estado_reserva, tipo_area;
        SQL);
    }
};
