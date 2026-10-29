<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Persistence;

use CondoHub\Reservas\Application\Port\EventosProcesados;
use Illuminate\Database\ConnectionInterface;

/** EventosProcesados sobre `evento_procesado` (S5): la clave primaria descarta el repetido sin carreras. */
final class PgEventosProcesados implements EventosProcesados
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function registrar(string $eventId, string $tipo): bool
    {
        return $this->db->affectingStatement(
            'INSERT INTO evento_procesado (event_id, event_type) VALUES (?::uuid, ?) ON CONFLICT (event_id) DO NOTHING',
            [$eventId, $tipo],
        ) === 1;
    }
}
