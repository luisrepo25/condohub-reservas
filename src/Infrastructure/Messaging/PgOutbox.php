<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Messaging;

use CondoHub\Reservas\Application\Port\Outbox;
use CondoHub\Reservas\Domain\Event\ComandoSaga;
use Illuminate\Database\ConnectionInterface;

/**
 * S4: el comando se guarda en `outbox` con la misma conexión (y transacción) que la reserva. `id` es el eventId del
 * sobre y `payload` es su `data`; el publicador arma el sobre del contrato al enviarlo a RabbitMQ.
 */
final class PgOutbox implements Outbox
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function guardar(ComandoSaga $comando): void
    {
        $this->db->insert(
            'INSERT INTO outbox (id, aggregate_type, aggregate_id, event_type, routing_key, saga_id, payload, occurred_at)
             VALUES (?::uuid, ?, ?, ?, ?, ?::uuid, ?::jsonb, to_timestamp(?))',
            [
                $comando->eventId(),
                'Reserva',
                $comando->reservaId(),
                substr($comando->tipo(), (int) strpos($comando->tipo(), '.') + 1),
                $comando->tipo(),
                $comando->sagaId(),
                json_encode($comando->data(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $comando->ocurrido()->getTimestamp(),
            ],
        );
    }
}
