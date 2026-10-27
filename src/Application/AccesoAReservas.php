<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application;

use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\Model\Reserva;
use CondoHub\Reservas\Domain\Rol;
use CondoHub\Reservas\Domain\Solicitante;

/**
 * F1: el ADMIN opera todas las reservas; un RESIDENTE, solo las de sus unidades. Qué unidades son suyas lo dice
 * Gestión (dueña de las unidades), no Reservas.
 */
final class AccesoAReservas
{
    public function __construct(private readonly FinanzasGateway $finanzas) {}

    /** @return list<string>|null unidades que puede ver el solicitante; null = todas (ADMIN) */
    public function unidadesVisibles(Solicitante $solicitante): ?array
    {
        return $solicitante->tieneAlgunRol(Rol::Admin) ? null : $this->finanzas->unidadesDelSolicitante();
    }

    public function exigirPuedeOperar(Solicitante $solicitante, Reserva $reserva): void
    {
        $unidades = $this->unidadesVisibles($solicitante);
        if ($unidades !== null && ! in_array($reserva->unidadId, $unidades, true)) {
            throw new ReglaNegocioViolada('F1', 'Solo puedes operar las reservas de tus unidades');
        }
    }
}
