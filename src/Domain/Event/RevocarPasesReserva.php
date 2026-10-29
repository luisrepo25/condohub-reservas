<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Event;

/**
 * Compensación del paso 3 de la saga, y cancelación de una reserva confirmada (R7): Acceso revoca los pases de la
 * reserva (idempotente).
 * Contrato: contracts/events/reservas.RevocarPasesReserva.schema.json.
 */
final readonly class RevocarPasesReserva implements ComandoSaga
{
    public const TIPO = 'reservas.RevocarPasesReserva';

    /** @param array{reservaId: string} $data */
    private function __construct(
        public string $eventId,
        public ?string $sagaId,
        public \DateTimeImmutable $ocurrido,
        public array $data,
    ) {}

    public static function de(string $eventId, ?string $sagaId, string $reservaId, \DateTimeImmutable $ahora): self
    {
        return new self($eventId, $sagaId, $ahora, ['reservaId' => $reservaId]);
    }

    public function tipo(): string
    {
        return self::TIPO;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function sagaId(): ?string
    {
        return $this->sagaId;
    }

    public function reservaId(): string
    {
        return $this->data['reservaId'];
    }

    public function ocurrido(): \DateTimeImmutable
    {
        return $this->ocurrido;
    }

    public function data(): array
    {
        return $this->data;
    }
}
