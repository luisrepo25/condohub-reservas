<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Port;

use CondoHub\Reservas\Application\Outbox\MensajePendiente;

/** Lectura y marcado de la tabla `outbox` para el publicador (S4). Se usa dentro de una transacción. */
interface OutboxPendiente
{
    /**
     * Los mensajes sin publicar más antiguos, en orden, bloqueados para esta transacción. Los que ya bloqueó otro
     * publicador se saltan (dos publicadores a la vez no envían el mismo mensaje).
     *
     * @return list<MensajePendiente>
     */
    public function tomarPendientes(int $limite): array;

    public function marcarPublicado(string $id): void;

    public function registrarIntentoFallido(string $id): void;
}
