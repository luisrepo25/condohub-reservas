<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Port;

use CondoHub\Reservas\Domain\Model\SagaReserva;

/** Repositorio del agregado SagaReserva (S1): `saga_instancia` y sus 4 filas de `saga_paso`. */
interface SagaRepository
{
    public function guardarNueva(SagaReserva $saga): void;

    /** sagaId de la reserva, para correlacionar los mensajes posteriores (cancelación, finalización). */
    public function idDeLaReserva(string $reservaId): ?string;

    /**
     * La saga, bloqueada hasta el fin de la transacción: una respuesta y una espera vencida nunca la avanzan a la vez.
     * Debe llamarse dentro de una transacción.
     */
    public function porIdParaActualizar(string $id): ?SagaReserva;

    /** Como porIdParaActualizar, buscando por la reserva (respuestas sin sagaId). */
    public function deLaReservaParaActualizar(string $reservaId): ?SagaReserva;

    /**
     * S2/S3: la saga en curso o compensando más atrasada cuyo plazo ya venció, bloqueada; las que otro proceso tiene
     * bloqueadas se saltan. Debe llamarse dentro de una transacción.
     */
    public function siguienteVencida(\DateTimeImmutable $ahora): ?SagaReserva;

    /** Guarda el nuevo estado de la saga y de sus pasos. */
    public function actualizar(SagaReserva $saga): void;
}
