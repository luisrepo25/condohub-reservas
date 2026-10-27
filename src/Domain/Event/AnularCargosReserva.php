<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Event;

use CondoHub\Reservas\Domain\Model\Reserva;
use CondoHub\Reservas\Domain\ValueObject\AlcanceAnulacion;

/**
 * Reservas pide a Gestión anular cargos de la reserva con nota de crédito (F6: nunca se borran): todos (cancelación a
 * tiempo, R7) o solo la garantía (cancelación tardía, R7, o finalizada sin daños, R6).
 * Contrato: contracts/events/reservas.AnularCargosReserva.schema.json. Gestión lo procesa una vez por reservaId.
 */
final readonly class AnularCargosReserva implements ComandoSaga
{
    public const TIPO = 'reservas.AnularCargosReserva';

    /** @param array{reservaId: string, unidadId: string, alcance: string} $data */
    private function __construct(
        public string $eventId,
        public ?string $sagaId,
        public \DateTimeImmutable $ocurrido,
        public array $data,
    ) {}

    public static function de(string $eventId, ?string $sagaId, Reserva $reserva, AlcanceAnulacion $alcance, \DateTimeImmutable $ahora): self
    {
        return new self($eventId, $sagaId, $ahora, [
            'reservaId' => $reserva->id,
            'unidadId' => $reserva->unidadId,
            'alcance' => $alcance->value,
        ]);
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
