<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Parámetro de R4 (expensas vencidas que bloquean una reserva).
 * Fuente: database/sql/002_parametro_morosidad.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/002_parametro_morosidad.sql')));
    }

    public function down(): void
    {
        DB::delete("DELETE FROM parametro WHERE clave = 'reserva.expensas_vencidas_bloqueo'");
    }
};
