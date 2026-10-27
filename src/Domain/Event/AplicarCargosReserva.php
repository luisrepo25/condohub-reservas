<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Event;

use CondoHub\Reservas\Domain\Model\AreaComun;
use CondoHub\Reservas\Domain\Model\Reserva;

/**
 * Comando del paso 2 de la saga: Reservas pide a Gestión aplicar los cargos de la reserva (R5).
 * Contrato: contracts/events/reservas.AplicarCargosReserva.schema.json. Gestión no crea cargos de monto 0.
 */
final readonly class AplicarCargosReserva implements ComandoSaga
{
    public const TIPO = 'reservas.AplicarCargosReserva';

    /** @param array{reservaId: string, unidadId: string, areaComun: string, costoUso: string, garantia: string} $data */
    private function __construct(
        public string $eventId,
        public string $sagaId,
        public \DateTimeImmutable $ocurrido,
        public array $data,
    ) {}

    public static function de(string $eventId, string $sagaId, Reserva $reserva, AreaComun $area, \DateTimeImmutable $ahora): self
    {
        return new self($eventId, $sagaId, $ahora, [
            'reservaId' => $reserva->id,
            'unidadId' => $reserva->unidadId,
            'areaComun' => $area->codigo,
            'costoUso' => (string) $reserva->costoUso,
            'garantia' => (string) $reserva->garantia,
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

    public function sagaId(): string
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
