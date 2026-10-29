<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Port;

/** Paso 3 de la saga: los invitados de la reserva con su id, que Acceso usa para devolver un pase por invitado. */
interface InvitadosParaPases
{
    /** @return list<array{invitadoId: string, nombre: string}> */
    public function deLaReserva(string $reservaId): array;
}
