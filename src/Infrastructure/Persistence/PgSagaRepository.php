<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Persistence;

use CondoHub\Reservas\Domain\Model\SagaReserva;
use CondoHub\Reservas\Domain\Port\SagaRepository;
use Illuminate\Database\ConnectionInterface;

/** SagaRepository sobre `saga_instancia` y `saga_paso` (S1): lo necesario para retomar la saga tras un reinicio. */
final class PgSagaRepository implements SagaRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function guardarNueva(SagaReserva $saga): void
    {
        $this->db->insert(
            'INSERT INTO saga_instancia (id, reserva_id, estado, paso_actual, proximo_reintento)
             VALUES (?::uuid, ?::uuid, ?::estado_saga, ?, to_timestamp(?))',
            [$saga->id, $saga->reservaId, $saga->estado, $saga->pasoActual, $saga->proximoReintento->getTimestamp()],
        );

        foreach (SagaReserva::NOMBRES as $numero => $nombre) {
            $estado = $saga->estadoDelPaso($numero);
            $this->db->insert(
                'INSERT INTO saga_paso (saga_id, numero, nombre, estado, intentos, comando_event_id)
                 VALUES (?::uuid, ?, ?, ?::estado_paso, ?, ?::uuid)',
                [
                    $saga->id,
                    $numero,
                    $nombre,
                    $estado,
                    $estado === 'PENDIENTE' ? 0 : 1,
                    $numero === $saga->pasoActual ? $saga->comandoEnCursoId : null,
                ],
            );
        }
    }
}
