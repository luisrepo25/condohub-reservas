<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pruebas contra PostgreSQL real (la restricción EXCLUDE y los triggers no existen en SQLite). Migra la base de
 * pruebas (`reservas_test`) una vez por ejecución y envuelve cada test en una transacción que se deshace al final:
 * los tests no dejan datos ni dependen del orden. Laravel llama a setUpBaseDeDatos() al usar el trait.
 */
trait BaseDeDatos
{
    private static bool $migrada = false;

    protected function setUpBaseDeDatos(): void
    {
        if (! self::$migrada) {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            self::$migrada = true;
        }
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(static fn () => DB::rollBack());
    }
}
