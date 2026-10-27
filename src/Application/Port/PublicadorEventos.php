<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Port;

use CondoHub\Reservas\Application\Errors\PublicacionFallida;
use CondoHub\Reservas\Application\Outbox\MensajePendiente;

/** Envía un mensaje al broker (RabbitMQ, exchange condohub.events) y vuelve solo cuando el broker confirmó recibirlo. */
interface PublicadorEventos
{
    /** @throws PublicacionFallida si el broker no está o no confirmó a tiempo */
    public function publicar(MensajePendiente $mensaje): void;
}
