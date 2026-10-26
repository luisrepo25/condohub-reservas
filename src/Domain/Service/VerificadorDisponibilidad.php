<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Service;

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\ValueObject\BloqueHorario;

/**
 * R2: dos reservas activas del mismo área no se solapan. Da un error claro antes de escribir; si dos pedidos llegan a la
 * vez, la restricción EXCLUDE de la base es la que decide (y el adaptador la traduce a la misma regla).
 */
final class VerificadorDisponibilidad
{
    /** @param list<BloqueHorario> $ocupados bloques de las reservas SOLICITADA o CONFIRMADA del área */
    public function exigirLibre(BloqueHorario $pedido, array $ocupados): void
    {
        foreach ($ocupados as $ocupado) {
            if ($pedido->seSolapaCon($ocupado)) {
                throw new ReglaNegocioViolada('R2', 'El área ya está reservada en ese horario');
            }
        }
    }
}
