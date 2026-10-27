<?php

declare(strict_types=1);

namespace Tests\Support;

use CondoHub\Reservas\Application\Port\Reloj;

/** Reloj detenido en una hora dada, para probar reglas de tiempo (R3, R7) sin depender del día de hoy. */
final class RelojFijo implements Reloj
{
    public function __construct(private readonly \DateTimeImmutable $ahora) {}

    public function ahora(): \DateTimeImmutable
    {
        return $this->ahora->setTimezone(new \DateTimeZone('UTC'));
    }
}
