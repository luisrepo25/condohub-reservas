<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Http\Presentacion;

use CondoHub\Reservas\Domain\Model\Reserva;

/** Forma `Reserva` del contrato (contracts/openapi/reservas.yaml): fechas ISO-8601 en UTC y dinero como string. */
final class ReservaJson
{
    /** @return array<string, string|int|bool|null> */
    public static function de(Reserva $reserva): array
    {
        return [
            'id' => $reserva->id,
            'areaId' => $reserva->areaId,
            'unidadId' => $reserva->unidadId,
            'inicio' => $reserva->bloque->inicio->format('Y-m-d\TH:i:s\Z'),
            'fin' => $reserva->bloque->fin->format('Y-m-d\TH:i:s\Z'),
            'estado' => $reserva->estado()->value,
            'costoUso' => (string) $reserva->costoUso,
            'garantia' => (string) $reserva->garantia,
            'cantidadInvitados' => $reserva->cantidadInvitados(),
            'conDanios' => $reserva->conDanios(),
            'motivo' => $reserva->motivo(),
            'version' => $reserva->version(),
        ];
    }
}
