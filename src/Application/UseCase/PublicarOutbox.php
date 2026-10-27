<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\UseCase;

use CondoHub\Reservas\Application\Errors\PublicacionFallida;
use CondoHub\Reservas\Application\Port\OutboxPendiente;
use CondoHub\Reservas\Application\Port\PublicadorEventos;
use CondoHub\Reservas\Application\Port\Transaccion;

/**
 * S4: envía a RabbitMQ los mensajes pendientes del outbox, en orden, y los marca publicados solo cuando el broker
 * confirmó recibirlos. Entrega "al menos una vez": si el proceso cae entre el envío y el marcado, el mensaje se
 * reenvía y el consumidor lo descarta por su eventId (S5). Si uno falla, se detiene para respetar el orden y el resto
 * espera a la siguiente vuelta.
 */
final class PublicarOutbox
{
    public function __construct(
        private readonly OutboxPendiente $outbox,
        private readonly PublicadorEventos $publicador,
        private readonly Transaccion $transaccion,
    ) {}

    public function ejecutar(int $lote): ResultadoPublicacion
    {
        if ($lote < 1) {
            throw new \InvalidArgumentException('El lote del publicador debe ser de al menos 1 mensaje');
        }

        return $this->transaccion->ejecutar(function () use ($lote): ResultadoPublicacion {
            $publicados = 0;
            foreach ($this->outbox->tomarPendientes($lote) as $mensaje) {
                try {
                    $this->publicador->publicar($mensaje);
                } catch (PublicacionFallida $e) {
                    $this->outbox->registrarIntentoFallido($mensaje->id);

                    return new ResultadoPublicacion($publicados, $mensaje->id, $e->getMessage());
                }
                $this->outbox->marcarPublicado($mensaje->id);
                $publicados++;
            }

            return new ResultadoPublicacion($publicados, null, null);
        });
    }
}
