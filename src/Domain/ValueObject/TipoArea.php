<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\ValueObject;

/** R1: tipos de área común. */
enum TipoArea: string
{
    case SalonEventos = 'SALON_EVENTOS';
    case Piscina = 'PISCINA';
    case Churrasquera = 'CHURRASQUERA';
}
