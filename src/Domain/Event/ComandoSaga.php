<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Event;

/**
 * Mensaje que Reservas publica por RabbitMQ con Outbox (S4). `tipo` es la routing key y el `type` del sobre de
 * contracts/events; `data` es el contenido que define su esquema.
 */
interface ComandoSaga
{
    public function tipo(): string;

    public function eventId(): string;

    /** Saga a la que pertenece (S1), si la hay. */
    public function sagaId(): ?string;

    /** Reserva sobre la que actúa (columna `aggregate_id` del outbox). */
    public function reservaId(): string;

    public function ocurrido(): \DateTimeImmutable;

    /** @return array<string, string> */
    public function data(): array;
}
