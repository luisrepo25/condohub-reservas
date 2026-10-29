<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Port;

/** S5: registro de los mensajes ya procesados (tabla `evento_procesado`), en la transacción de su efecto. */
interface EventosProcesados
{
    /** Anota el mensaje; false si ya estaba (un repetido que se descarta). */
    public function registrar(string $eventId, string $tipo): bool;
}
