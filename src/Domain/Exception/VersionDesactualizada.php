<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Exception;

/** O5: el cliente editó una versión vieja de la reserva. HTTP: 409. */
final class VersionDesactualizada extends \DomainException
{
    public function __construct(public readonly int $esperada, public readonly int $recibida)
    {
        parent::__construct("La reserva cambió: está en la versión {$esperada} y se envió la {$recibida}");
    }
}
