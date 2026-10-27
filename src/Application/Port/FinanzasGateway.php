<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Port;

use CondoHub\Reservas\Application\Errors\FinanzasNoDisponible;
use CondoHub\Reservas\Application\Errors\UnidadInexistente;
use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;

/**
 * Lo que Reservas necesita del contexto Finanzas (Gestión), dueño de las unidades y la morosidad. Son consultas de
 * solo lectura hechas con la identidad de quien hace la petición, así Gestión aplica F1 con sus propias reglas.
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

    /**
     * F1: unidades de las que el solicitante es titular o residente (`miPerfil`). Vacío si su cuenta no está enlazada.
     *
     * @return list<string>
     *
     * @throws FinanzasNoDisponible Gestión no respondió o respondió algo inesperado
     */
    public function unidadesDelSolicitante(): array;
}
