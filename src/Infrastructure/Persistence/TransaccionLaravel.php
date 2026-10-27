<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Persistence;

use CondoHub\Reservas\Application\Port\Transaccion;
use Illuminate\Database\ConnectionInterface;

/** Transacción de PostgreSQL: si `$trabajo` lanza una excepción, se deshace todo y la excepción sigue su camino. */
final class TransaccionLaravel implements Transaccion
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function ejecutar(callable $trabajo): mixed
    {
        return $this->db->transaction(static fn () => $trabajo());
    }
}
