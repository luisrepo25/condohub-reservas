<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Persistence;

use CondoHub\Reservas\Domain\Model\SagaReserva;
use CondoHub\Reservas\Domain\Port\SagaRepository;
use Illuminate\Database\ConnectionInterface;

/** SagaRepository sobre `saga_instancia` y `saga_paso` (S1): lo necesario para retomar la saga tras un reinicio. */
final class PgSagaRepository implements SagaRepository
{
    private const COLUMNAS = 'id, reserva_id, estado, paso_actual, extract(epoch FROM proximo_reintento)::bigint AS proximo,
        contexto::text AS contexto, ultimo_error';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function idDeLaReserva(string $reservaId): ?string
    {
        $id = $this->db->scalar('SELECT id FROM saga_instancia WHERE reserva_id = ?::uuid', [$reservaId]);

        return is_string($id) ? $id : null;
    }

    public function guardarNueva(SagaReserva $saga): void
    {
        $this->db->insert(
            'INSERT INTO saga_instancia (id, reserva_id, estado, paso_actual, proximo_reintento, contexto)
             VALUES (?::uuid, ?::uuid, ?::estado_saga, ?, to_timestamp(?), ?::jsonb)',
            [
                $saga->id, $saga->reservaId, $saga->estado, $saga->pasoActual, $saga->proximoReintento->getTimestamp(),
                self::json($saga->contexto),
            ],
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

    public function porIdParaActualizar(string $id): ?SagaReserva
    {
        return $this->una('id = ?::uuid FOR UPDATE', $id);
    }

    public function deLaReservaParaActualizar(string $reservaId): ?SagaReserva
    {
        return $this->una('reserva_id = ?::uuid FOR UPDATE', $reservaId);
    }

    public function siguienteVencida(\DateTimeImmutable $ahora): ?SagaReserva
    {
        // Índice parcial ix_saga_pendiente; SKIP LOCKED: dos orquestadores nunca revisan la misma saga.
        return $this->una(
            "estado IN ('EN_CURSO', 'COMPENSANDO') AND proximo_reintento <= to_timestamp(?)
             ORDER BY proximo_reintento, id LIMIT 1 FOR UPDATE SKIP LOCKED",
            $ahora->getTimestamp(),
        );
    }

    public function actualizar(SagaReserva $saga): void
    {
        $this->db->update(
            "UPDATE saga_instancia
                SET estado = ?::estado_saga, paso_actual = ?, proximo_reintento = to_timestamp(?), ultimo_error = ?,
                    contexto = ?::jsonb, actualizada_en = now(),
                    terminada_en = CASE WHEN ?::estado_saga IN ('COMPLETADA', 'COMPENSADA') THEN now() END
              WHERE id = ?::uuid",
            [
                $saga->estado, $saga->pasoActual, $saga->proximoReintento->getTimestamp(), $saga->ultimoError,
                self::json($saga->contexto), $saga->estado, $saga->id,
            ],
        );
        foreach ($saga->pasosActuales() as $numero => $estado) {
            // El comando en curso se anota en su paso: el de los pases, o la anulación de los cargos al compensar.
            $enCurso = $numero === $saga->pasoActual
                && ($estado === 'EN_CURSO' || $saga->estado === SagaReserva::COMPENSANDO);
            $this->db->update(
                "UPDATE saga_paso
                    SET estado = ?::estado_paso,
                        intentos = CASE WHEN estado = 'PENDIENTE' AND ?::estado_paso <> 'PENDIENTE' THEN 1 ELSE intentos END,
                        intentos_compensacion = CASE WHEN numero = 2 THEN ? ELSE intentos_compensacion END,
                        comando_event_id = CASE WHEN ? THEN ?::uuid ELSE comando_event_id END,
                        actualizado_en = now()
                  WHERE saga_id = ?::uuid AND numero = ?",
                [
                    $estado, $estado, $saga->intentosCompensacion, $enCurso, $saga->comandoEnCursoId, $saga->id,
                    $numero,
                ],
            );
        }
    }

    private function una(string $condicion, string|int $valor): ?SagaReserva
    {
        $fila = $this->db->selectOne('SELECT '.self::COLUMNAS.' FROM saga_instancia WHERE '.$condicion, [$valor]);
        if ($fila === null) {
            return null;
        }
        /** @var object{id: string, reserva_id: string, estado: string, paso_actual: int, proximo: int|string|null, contexto: string, ultimo_error: ?string} $fila */
        $pasos = [];
        $comando = '';
        $intentosCompensacion = 0;
        $filasPaso = $this->db->select(
            'SELECT numero, estado, comando_event_id, intentos_compensacion FROM saga_paso WHERE saga_id = ?::uuid ORDER BY numero',
            [$fila->id],
        );
        foreach ($filasPaso as $paso) {
            /** @var object{numero: int, estado: string, comando_event_id: ?string, intentos_compensacion: int} $paso */
            $pasos[(int) $paso->numero] = $paso->estado;
            if ((int) $paso->numero === (int) $fila->paso_actual) {
                $comando = (string) $paso->comando_event_id;
            }
            if ((int) $paso->numero === SagaReserva::PASO_APLICAR_CARGOS) {
                $intentosCompensacion = (int) $paso->intentos_compensacion;
            }
        }
        $contexto = json_decode($fila->contexto, true);

        return SagaReserva::reconstituir(
            $fila->id,
            $fila->reserva_id,
            $fila->estado,
            (int) $fila->paso_actual,
            new \DateTimeImmutable('@'.($fila->proximo ?? 0)),
            $comando,
            is_array($contexto) ? $contexto : [],
            $pasos,
            $intentosCompensacion,
            $fila->ultimo_error,
        );
    }

    /** @param array<string, mixed> $valor */
    private static function json(array $valor): string
    {
        return json_encode((object) $valor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
