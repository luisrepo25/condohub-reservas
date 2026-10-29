<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Event;

use CondoHub\Reservas\Domain\Model\Reserva;

/**
 * La saga terminó bien: paso 4, n8n avisa al residente. Contrato: contracts/events/reservas.ReservaConfirmada.schema.json.
 * Las horas viajan en UTC; n8n las muestra en la hora del condominio.
 */
final readonly class ReservaConfirmada implements ComandoSaga
{
    public const TIPO = 'reservas.ReservaConfirmada';

    /** @param array{reservaId: string, unidadId: string, areaComun: string, inicio: string, fin: string} $data */
    private function __construct(
        public string $eventId,
        public string $sagaId,
        public \DateTimeImmutable $ocurrido,
        public array $data,
    ) {}

    /** @param string $areaComun nombre del área para el aviso (ej. "Salón de eventos") */
    public static function de(string $eventId, string $sagaId, Reserva $reserva, string $areaComun, \DateTimeImmutable $ahora): self
    {
        $utc = new \DateTimeZone('UTC');

        return new self($eventId, $sagaId, $ahora, [
            'reservaId' => $reserva->id,
            'unidadId' => $reserva->unidadId,
            'areaComun' => $areaComun,
            'inicio' => $reserva->bloque->inicio->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'fin' => $reserva->bloque->fin->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
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
