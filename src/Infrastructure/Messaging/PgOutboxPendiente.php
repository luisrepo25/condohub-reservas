<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Messaging;

use CondoHub\Reservas\Application\Outbox\MensajePendiente;
use CondoHub\Reservas\Application\Port\OutboxPendiente;
use Illuminate\Database\ConnectionInterface;

/** OutboxPendiente sobre la tabla `outbox`. `FOR UPDATE SKIP LOCKED`: dos publicadores nunca toman el mismo mensaje. */
final class PgOutboxPendiente implements OutboxPendiente
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function tomarPendientes(int $limite): array
    {
        if ($this->db->transactionLevel() === 0) {
            throw new \LogicException('S4: tomar mensajes del outbox exige una transacción (los bloquea hasta marcarlos)');
        }
        $filas = $this->db->select(
            'SELECT id, routing_key, saga_id, extract(epoch FROM occurred_at)::bigint AS ocurrido, payload::text AS payload
               FROM outbox
              WHERE published_at IS NULL
              ORDER BY occurred_at, secuencia
              LIMIT ?
                FOR UPDATE SKIP LOCKED',
            [$limite],
        );

        return array_values(array_map(static function (object $f): MensajePendiente {
            /** @var object{id: string, routing_key: string, saga_id: ?string, ocurrido: int|string, payload: string} $f */
            return new MensajePendiente($f->id, $f->routing_key, $f->saga_id, new \DateTimeImmutable('@'.$f->ocurrido), $f->payload);
        }, $filas));
    }

    public function marcarPublicado(string $id): void
    {
        $this->db->update('UPDATE outbox SET published_at = now(), intentos = intentos + 1 WHERE id = ?::uuid', [$id]);
    }

    public function registrarIntentoFallido(string $id): void
    {
        $this->db->update('UPDATE outbox SET intentos = intentos + 1 WHERE id = ?::uuid', [$id]);
    }
}
