<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Port;

use CondoHub\Reservas\Application\Errors\FinanzasNoDisponible;
use CondoHub\Reservas\Application\Errors\UnidadInexistente;
use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;

/**
 * Lo que Reservas necesita del contexto Finanzas (Gestión), dueño de la morosidad. Es una consulta de solo lectura hecha
 * con la identidad de quien pide la reserva, así Gestión aplica F1 (un residente solo ve su unidad).
 */
interface FinanzasGateway
{
    /**
     * R4: expensas vencidas e impagas de la unidad.
     *
     * @throws ReglaNegocioViolada F1: la unidad no es del solicitante
     * @throws UnidadInexistente la unidad no existe en Finanzas
     * @throws FinanzasNoDisponible Gestión no respondió o respondió algo inesperado
     */
    public function expensasVencidas(string $unidadId): int;
}
