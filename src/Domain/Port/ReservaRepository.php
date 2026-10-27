<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Port;

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\Exception\ReservaYaRegistrada;
use CondoHub\Reservas\Domain\Model\Reserva;
use CondoHub\Reservas\Domain\ValueObject\BloqueHorario;
use CondoHub\Reservas\Domain\ValueObject\IdempotencyKey;

/** Repositorio del agregado Reserva (R2, R3, O3). */
interface ReservaRepository
{
    /** O3: la reserva creada con esa clave, si existe. */
    public function porIdempotencyKey(IdempotencyKey $clave): ?Reserva;

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
}
