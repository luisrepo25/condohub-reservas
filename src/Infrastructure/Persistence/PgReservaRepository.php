<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Persistence;

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\Exception\ReservaYaRegistrada;
use CondoHub\Reservas\Domain\Model\Reserva;
use CondoHub\Reservas\Domain\Port\ReservaRepository;
use CondoHub\Reservas\Domain\ValueObject\BloqueHorario;
use CondoHub\Reservas\Domain\ValueObject\Dinero;
use CondoHub\Reservas\Domain\ValueObject\EstadoReserva;
use CondoHub\Reservas\Domain\ValueObject\IdempotencyKey;
use CondoHub\Reservas\Domain\ValueObject\Invitado;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * ReservaRepository sobre `reserva` e `invitado_reserva` (SQL directo). Las fechas viajan como segundos desde 1970 para
 * no depender de la zona horaria de la sesión de PostgreSQL: todo es UTC.
 */
final class PgReservaRepository implements ReservaRepository
{
    private const COLUMNAS = 'id, area_id, unidad_id, solicitada_por, extract(epoch FROM inicio)::bigint AS inicio,
        extract(epoch FROM fin)::bigint AS fin, estado, costo_uso::text AS costo_uso, garantia::text AS garantia,
        con_danios, motivo, idempotency_key, version';

    /** Códigos de error de PostgreSQL. */
    private const EXCLUSION_VIOLADA = '23P01';

    private const UNICIDAD_VIOLADA = '23505';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function porIdempotencyKey(IdempotencyKey $clave): ?Reserva
    {
        $fila = $this->db->selectOne('SELECT '.self::COLUMNAS.' FROM reserva WHERE idempotency_key = ?::uuid', [$clave->valor]);

        return $fila === null ? null : $this->aReserva($fila);
    }

    public function contarActivasBloqueandoUnidad(string $unidadId): int
    {
        if ($this->db->transactionLevel() === 0) {
            throw new \LogicException('R3: contar las reservas activas bloqueando la unidad exige una transacción');
        }
        // Candado de la unidad hasta el fin de la transacción: la segunda solicitud simultánea espera y cuenta bien.
        $this->db->select("SELECT pg_advisory_xact_lock(hashtext('reserva-unidad:' || ?))", [$unidadId]);
        $fila = $this->db->selectOne(
            "SELECT count(*) AS activas FROM reserva WHERE unidad_id = ?::uuid AND estado IN ('SOLICITADA', 'CONFIRMADA')",
            [$unidadId],
        );

        return (int) ($fila->activas ?? 0);
    }

    public function bloquesOcupados(string $areaId, BloqueHorario $pedido): array
    {
        $filas = $this->db->select(
            "SELECT extract(epoch FROM inicio)::bigint AS inicio, extract(epoch FROM fin)::bigint AS fin
               FROM reserva
              WHERE area_id = ?::uuid AND estado IN ('SOLICITADA', 'CONFIRMADA')
                AND horario && tstzrange(to_timestamp(?), to_timestamp(?), '[)')
              ORDER BY inicio",
            [$areaId, $pedido->inicio->getTimestamp(), $pedido->fin->getTimestamp()],
        );

        return array_values(array_map(static fn (object $f): BloqueHorario => self::bloque($f), $filas));
    }

    public function guardarNueva(Reserva $reserva): void
    {
        try {
            $this->db->insert(
                'INSERT INTO reserva (id, area_id, unidad_id, solicitada_por, inicio, fin, cantidad_invitados, estado,
                                      costo_uso, garantia, idempotency_key, version)
                 VALUES (?::uuid, ?::uuid, ?::uuid, ?, to_timestamp(?), to_timestamp(?), ?, ?::estado_reserva,
                         ?::numeric, ?::numeric, ?::uuid, ?)',
                [
                    $reserva->id, $reserva->areaId, $reserva->unidadId, $reserva->solicitadaPor,
                    $reserva->bloque->inicio->getTimestamp(), $reserva->bloque->fin->getTimestamp(),
                    $reserva->cantidadInvitados(), $reserva->estado()->value, (string) $reserva->costoUso,
                    (string) $reserva->garantia, $reserva->idempotencyKey->valor, $reserva->version(),
                ],
            );
        } catch (QueryException $e) {
            throw $this->traducir($e);
        }

        foreach ($reserva->invitados as $invitado) {
            $this->db->insert(
                'INSERT INTO invitado_reserva (reserva_id, nombre, documento) VALUES (?::uuid, ?, ?)',
                [$reserva->id, $invitado->nombre, $invitado->documento],
            );
        }
    }

    private function traducir(QueryException $e): \Throwable
    {
        $estado = $e->errorInfo[0] ?? null;
        if ($estado === self::EXCLUSION_VIOLADA) {
            return new ReglaNegocioViolada('R2', 'El área ya está reservada en ese horario');
        }
        if ($estado === self::UNICIDAD_VIOLADA && str_contains($e->getMessage(), 'idempotency_key')) {
            return new ReservaYaRegistrada('Otra solicitud con la misma Idempotency-Key se guardó antes', previous: $e);
        }

        return $e;
    }

    private function aReserva(object $fila): Reserva
    {
        /** @var object{id: string, area_id: string, unidad_id: string, solicitada_por: string, inicio: int, fin: int, estado: string, costo_uso: string, garantia: string, con_danios: ?bool, motivo: ?string, idempotency_key: string, version: int} $fila */
        $invitados = $this->db->select(
            'SELECT nombre, documento FROM invitado_reserva WHERE reserva_id = ?::uuid ORDER BY nombre, id',
            [$fila->id],
        );

        return Reserva::reconstituir(
            $fila->id,
            $fila->area_id,
            $fila->unidad_id,
            $fila->solicitada_por,
            self::bloque($fila),
            array_values(array_map(static fn (object $i): Invitado => self::invitado($i), $invitados)),
            Dinero::de($fila->costo_uso),
            Dinero::de($fila->garantia),
            IdempotencyKey::de($fila->idempotency_key),
            EstadoReserva::from($fila->estado),
            $fila->con_danios === null ? null : (bool) $fila->con_danios,
            $fila->motivo,
            (int) $fila->version,
        );
    }

    private static function invitado(object $fila): Invitado
    {
        /** @var object{nombre: string, documento: ?string} $fila */
        return new Invitado($fila->nombre, $fila->documento);
    }

    private static function bloque(object $fila): BloqueHorario
    {
        /** @var object{inicio: int|string, fin: int|string} $fila */
        return new BloqueHorario(
            new \DateTimeImmutable('@'.$fila->inicio),
            new \DateTimeImmutable('@'.$fila->fin),
        );
    }
}
