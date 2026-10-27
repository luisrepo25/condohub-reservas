<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Http\Controllers;

use CondoHub\Reservas\Application\UseCase\SolicitarReserva;
use CondoHub\Reservas\Domain\Solicitante;
use CondoHub\Reservas\Infrastructure\Http\Presentacion\ReservaJson;
use CondoHub\Reservas\Infrastructure\Http\Requests\SolicitarReservaRequest;
use Illuminate\Http\JsonResponse;

/** Endpoints de reservas. Sin lógica de negocio: validan la forma, llaman al caso de uso y dan la forma del contrato. */
final class ReservasController
{
    /**
     * `POST /api/reservas`: 202 con la reserva SOLICITADA (la saga sigue en segundo plano). Repetir la misma
     * Idempotency-Key responde lo mismo: 202 con la misma reserva (O3).
     */
    public function solicitar(SolicitarReservaRequest $request, SolicitarReserva $solicitarReserva): JsonResponse
    {
        /** @var Solicitante $solicitante */
        $solicitante = $request->attributes->get('solicitante');
        $resultado = $solicitarReserva->ejecutar($solicitante, $request->solicitud());

        return new JsonResponse(ReservaJson::de($resultado->reserva), 202);
    }
}
