<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Service;

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\ValueObject\ParametrosReserva;

/**
 * R4: una unidad morosa con N o más expensas vencidas no puede reservar. La cantidad la informa Gestión (dueña de la
 * morosidad); el límite N es un parámetro de Reservas. Se evalúa antes de iniciar la saga.
 */
final class PoliticaMorosidad
{
    public function exigirPuedeReservar(int $expensasVencidas, ParametrosReserva $parametros): void
    {
        if ($expensasVencidas >= $parametros->expensasVencidasBloqueo) {
            throw new ReglaNegocioViolada(
                'R4',
                "La unidad tiene {$expensasVencidas} expensas vencidas: con {$parametros->expensasVencidasBloqueo} o más"
                .' no puede reservar hasta ponerse al día',
            );
        }
    }
}
