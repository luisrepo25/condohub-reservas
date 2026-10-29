<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Event;

use CondoHub\Reservas\Domain\Model\Reserva;

/**
 * Comando del paso 3 de la saga: Reservas pide a Acceso emitir un pase QR por invitado para el día de la reserva (A1).
 * Contrato: contracts/events/reservas.EmitirPasesReserva.schema.json. Acceso identifica la unidad por su código.
 */
final readonly class EmitirPasesReserva implements ComandoSaga
{
    public const TIPO = 'reservas.EmitirPasesReserva';

    /**
     * @param  array{reservaId: string, unidadId: string, unidadCodigo: string, fecha: string, invitados: list<array{invitadoId: string, nombre: string}>}  $data
     */
    private function __construct(
        public string $eventId,
        public string $sagaId,
        public \DateTimeImmutable $ocurrido,
        public array $data,
    ) {}

    /**
     * @param  list<array{invitadoId: string, nombre: string}>  $invitados
     * @param  \DateTimeZone  $zona  la del condominio: el día del pase es el día local del inicio de la reserva
     */
    public static function de(
        string $eventId,
        string $sagaId,
        Reserva $reserva,
        string $unidadCodigo,
        array $invitados,
        \DateTimeZone $zona,
        \DateTimeImmutable $ahora,
    ): self {
        return new self($eventId, $sagaId, $ahora, [
            'reservaId' => $reserva->id,
            'unidadId' => $reserva->unidadId,
            'unidadCodigo' => $unidadCodigo,
            'fecha' => $reserva->bloque->inicio->setTimezone($zona)->format('Y-m-d'),
            'invitados' => $invitados,
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
