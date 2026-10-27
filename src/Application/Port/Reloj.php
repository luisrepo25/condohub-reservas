<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Port;

/** Hora actual en UTC. Puerto para que las reglas de tiempo (R3, R7) se prueben con una hora fija. */
interface Reloj
{
    public function ahora(): \DateTimeImmutable;
}
