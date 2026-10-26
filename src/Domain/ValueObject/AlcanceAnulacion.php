<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\ValueObject;

/** Qué cargos de la reserva anula Gestión con nota de crédito (evento reservas.AnularCargosReserva). */
enum AlcanceAnulacion: string
{
    /** Saga fallida o cancelación con anticipación suficiente (R7). */
    case Todos = 'TODOS';
    /** Cancelación tardía (R7) o finalizada sin daños (R6): se devuelve solo la garantía. */
    case SoloGarantia = 'SOLO_GARANTIA';
}
