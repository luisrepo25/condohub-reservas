<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Outbox;

/** Una fila de `outbox` aún no publicada: lo necesario para armar el sobre del contrato (contracts/events). */
final readonly class MensajePendiente
{
    public function __construct(
        /** eventId del sobre */
        public string $id,
        /** routing key = `type` del sobre, ej. reservas.AplicarCargosReserva */
        public string $tipo,
        public ?string $sagaId,
        public \DateTimeImmutable $ocurrido,
        /** `data` del sobre, tal como se guardó (JSON) */
        public string $dataJson,
    ) {}
}
