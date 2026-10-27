<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Port;

use CondoHub\Reservas\Domain\Event\AplicarCargosReserva;

/**
 * S4: guarda el mensaje en la tabla `outbox` dentro de la transacción en curso; el publicador lo envía después a
 * RabbitMQ (exchange condohub.events, routing key = tipo). Si la transacción se deshace, el mensaje tampoco existe.
 */
interface Outbox
{
    public function guardar(AplicarCargosReserva $comando): void;
}
