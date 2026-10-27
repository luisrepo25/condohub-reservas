<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\UseCase;

use CondoHub\Reservas\Domain\ValueObject\Invitado;

/** Datos de `POST /api/reservas` ya validados en su forma por el adaptador HTTP. Fechas con zona (se pasan a UTC). */
final readonly class SolicitudReserva
{
    /** @param list<Invitado> $invitados */
    public function __construct(
        public string $idempotencyKey,
        public string $areaId,
        public string $unidadId,
        public \DateTimeImmutable $inicio,
        public \DateTimeImmutable $fin,
        public array $invitados,
    ) {}
}
