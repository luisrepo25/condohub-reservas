<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\ValueObject;

/** R8: SOLICITADA → CONFIRMADA → FINALIZADA, SOLICITADA → FALLIDA, CONFIRMADA → CANCELADA. */
enum EstadoReserva: string
{
    case Solicitada = 'SOLICITADA';
    case Confirmada = 'CONFIRMADA';
    case Finalizada = 'FINALIZADA';
    case Fallida = 'FALLIDA';
    case Cancelada = 'CANCELADA';

    public function puedePasarA(self $destino): bool
    {
        return match ($this) {
            self::Solicitada => $destino === self::Confirmada || $destino === self::Fallida,
            self::Confirmada => $destino === self::Finalizada || $destino === self::Cancelada,
            default => false,
        };
    }

    /** R3: cuenta para el máximo de reservas activas y ocupa el horario (R2). */
    public function esActiva(): bool
    {
        return $this === self::Solicitada || $this === self::Confirmada;
    }
}
