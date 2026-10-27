<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\UseCase;

use CondoHub\Reservas\Domain\Model\Reserva;

/** La reserva solicitada y si es la repetición de una solicitud anterior con la misma Idempotency-Key (O3). */
final readonly class ResultadoSolicitud
{
    public function __construct(public Reserva $reserva, public bool $repetida) {}
}
