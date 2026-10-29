<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Event;

use CondoHub\Reservas\Domain\Model\Reserva;

/**
 * La saga falló y se compensó (S2): n8n avisa al residente con el motivo.
 * Contrato: contracts/events/reservas.ReservaFallida.schema.json.
 */
final readonly class ReservaFallida implements ComandoSaga
{
    public const TIPO = 'reservas.ReservaFallida';

    /** @param array{reservaId: string, unidadId: string, motivo: string} $data */
    private function __construct(
        public string $eventId,
        public string $sagaId,
        public \DateTimeImmutable $ocurrido,
        public array $data,
    ) {}

    public static function de(string $eventId, string $sagaId, Reserva $reserva, \DateTimeImmutable $ahora): self
    {
        return new self($eventId, $sagaId, $ahora, [
            'reservaId' => $reserva->id,
            'unidadId' => $reserva->unidadId,
            'motivo' => (string) $reserva->motivo(),
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
