<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Port;

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\Exception\ReservaYaRegistrada;
use CondoHub\Reservas\Domain\Exception\VersionDesactualizada;
use CondoHub\Reservas\Domain\Model\Reserva;
use CondoHub\Reservas\Domain\ValueObject\BloqueHorario;
use CondoHub\Reservas\Domain\ValueObject\EstadoReserva;
use CondoHub\Reservas\Domain\ValueObject\IdempotencyKey;

/** Repositorio del agregado Reserva (R2, R3, O3, O5). */
interface ReservaRepository
{
    public function porId(string $id): ?Reserva;

    /** O3: la reserva creada con esa clave, si existe. */
    public function porIdempotencyKey(IdempotencyKey $clave): ?Reserva;

    /** O3: la reserva cancelada con esa clave, si existe. */
    public function porClaveCancelacion(IdempotencyKey $clave): ?Reserva;

    /**
     * Reservas filtradas, ordenadas por inicio.
     *
     * @param  list<string>|null  $unidades  solo de estas unidades; null = de todas
     * @return list<Reserva>
     */
    public function listar(?array $unidades, ?EstadoReserva $estado): array;

    /**
     * R3: reservas activas (SOLICITADA o CONFIRMADA) de la unidad. Bloquea la unidad hasta el fin de la transacción, así
     * dos solicitudes simultáneas de la misma unidad no superan juntas el máximo. Debe llamarse dentro de una transacción.
     */
    public function contarActivasBloqueandoUnidad(string $unidadId): int;

    /**
     * R2: bloques de las reservas activas del área que se solapan con `$pedido`.
     *
     * @return list<BloqueHorario>
     */
    public function bloquesOcupados(string $areaId, BloqueHorario $pedido): array;

    /**
     * Guarda una reserva nueva con sus invitados.
     *
     * @throws ReglaNegocioViolada R2 si la base detecta un solape (dos pedidos simultáneos del mismo horario)
     * @throws ReservaYaRegistrada O3 si otra petición con la misma Idempotency-Key la guardó antes
     */
    public function guardarNueva(Reserva $reserva): void;

    /**
     * O5: guarda el nuevo estado solo si nadie la cambió desde `$versionLeida` (bloqueo optimista).
     *
     * @param  IdempotencyKey|null  $claveCancelacion  O3: la clave de la cancelación, si es una cancelación
     *
     * @throws VersionDesactualizada si otra petición la modificó antes
     */
    public function actualizar(Reserva $reserva, int $versionLeida, ?IdempotencyKey $claveCancelacion = null): void;
}
