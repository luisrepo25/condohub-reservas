<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Messaging;

/**
 * Decide si una conexión que lleva ociosa demasiado tiempo debe descartarse antes de volver a usarla. El publicador del
 * outbox espera entre vueltas sin atender la conexión (no puede enviar latidos), así que en lugar de heartbeat abre
 * una conexión nueva cuando pasó más tiempo del que el firewall de la nube tarda en descartar un flujo ocioso.
 */
final class ReconexionPorOciosidad
{
    public function __construct(private readonly float $maximaOciosidadSegundos = 30.0) {}

    public function conviene(?float $ultimoUso, float $ahora): bool
    {
        return $ultimoUso !== null && ($ahora - $ultimoUso) > $this->maximaOciosidadSegundos;
    }
}
