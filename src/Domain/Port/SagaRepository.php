<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Port;

use CondoHub\Reservas\Domain\Model\SagaReserva;

/** Repositorio del agregado SagaReserva (S1): `saga_instancia` y sus 4 filas de `saga_paso`. */
interface SagaRepository
{
    public function guardarNueva(SagaReserva $saga): void;
}
