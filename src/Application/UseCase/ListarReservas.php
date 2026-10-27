<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\UseCase;

use CondoHub\Reservas\Application\AccesoAReservas;
use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\Model\Reserva;
use CondoHub\Reservas\Domain\Port\ReservaRepository;
use CondoHub\Reservas\Domain\Solicitante;
use CondoHub\Reservas\Domain\ValueObject\EstadoReserva;

/** `GET /api/reservas`: el RESIDENTE ve solo las de sus unidades (F1); el ADMIN, todas. Filtros opcionales. */
final class ListarReservas
{
    public function __construct(
        private readonly ReservaRepository $reservas,
        private readonly AccesoAReservas $acceso,
    ) {}

    /** @return list<Reserva> */
    public function ejecutar(Solicitante $solicitante, ?string $unidadId, ?EstadoReserva $estado): array
    {
        $visibles = $this->acceso->unidadesVisibles($solicitante);
        if ($unidadId === null) {
            return $this->reservas->listar($visibles, $estado);
        }
        if ($visibles !== null && ! in_array($unidadId, $visibles, true)) {
            throw new ReglaNegocioViolada('F1', 'Solo puedes ver las reservas de tus unidades');
        }

        return $this->reservas->listar([$unidadId], $estado);
    }
}
