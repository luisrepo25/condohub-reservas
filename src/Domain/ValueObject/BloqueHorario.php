<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\ValueObject;

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;

/**
 * Intervalo [inicio, fin) de una reserva, en UTC. Detecta solapamientos (R2): una reserva que termina a las 14:00 no
 * choca con otra que empieza a las 14:00.
 */
final readonly class BloqueHorario
{
    public \DateTimeImmutable $inicio;

    public \DateTimeImmutable $fin;

    public function __construct(\DateTimeImmutable $inicio, \DateTimeImmutable $fin)
    {
        $utc = new \DateTimeZone('UTC');
        $inicio = $inicio->setTimezone($utc);
        $fin = $fin->setTimezone($utc);
        if ($fin <= $inicio) {
            throw new ReglaNegocioViolada('R2', 'El fin de la reserva debe ser posterior al inicio');
        }
        $this->inicio = $inicio;
        $this->fin = $fin;
    }

    public function duracionEnMinutos(): int
    {
        return intdiv($this->fin->getTimestamp() - $this->inicio->getTimestamp(), 60);
    }

    public function seSolapaCon(self $otro): bool
    {
        return $this->inicio < $otro->fin && $otro->inicio < $this->fin;
    }

    /** Minutos completos que faltan desde `$ahora` hasta el inicio (negativo si ya empezó). */
    public function minutosHastaElInicio(\DateTimeImmutable $ahora): int
    {
        return intdiv($this->inicio->getTimestamp() - $ahora->getTimestamp(), 60);
    }
}
