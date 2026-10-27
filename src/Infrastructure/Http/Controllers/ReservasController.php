<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Http\Controllers;

use CondoHub\Reservas\Application\UseCase\CancelarReserva;
use CondoHub\Reservas\Application\UseCase\FinalizarReserva;
use CondoHub\Reservas\Application\UseCase\ListarReservas;
use CondoHub\Reservas\Application\UseCase\SolicitarReserva;
use CondoHub\Reservas\Domain\Solicitante;
use CondoHub\Reservas\Infrastructure\Http\Presentacion\ReservaJson;
use CondoHub\Reservas\Infrastructure\Http\Requests\CancelarReservaRequest;
use CondoHub\Reservas\Infrastructure\Http\Requests\FinalizarReservaRequest;
use CondoHub\Reservas\Infrastructure\Http\Requests\ListarReservasRequest;
use CondoHub\Reservas\Infrastructure\Http\Requests\SolicitarReservaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Endpoints de reservas. Sin lógica de negocio: validan la forma, llaman al caso de uso y dan la forma del contrato. */
final class ReservasController
{
    /**
     * `POST /api/reservas`: 202 con la reserva SOLICITADA (la saga sigue en segundo plano). Repetir la misma
     * Idempotency-Key responde lo mismo: 202 con la misma reserva (O3).
     */
    public function solicitar(SolicitarReservaRequest $request, SolicitarReserva $solicitarReserva): JsonResponse
    {
        $resultado = $solicitarReserva->ejecutar(self::solicitante($request), $request->solicitud());

        return new JsonResponse(ReservaJson::de($resultado->reserva), 202);
    }

    /** `GET /api/reservas`: el RESIDENTE ve las de sus unidades; el ADMIN, todas (F1). */
    public function listar(ListarReservasRequest $request, ListarReservas $listarReservas): JsonResponse
    {
        $reservas = $listarReservas->ejecutar(self::solicitante($request), $request->unidadId(), $request->estado());

        return new JsonResponse(array_map(ReservaJson::de(...), $reservas));
    }

    /** `POST /api/reservas/{id}/cancelar` (R7, O3, O5). */
    public function cancelar(CancelarReservaRequest $request, string $id, CancelarReserva $cancelarReserva): JsonResponse
    {
        $reserva = $cancelarReserva->ejecutar(
            self::solicitante($request), strtolower($id), $request->idempotencyKey(), $request->motivo(), $request->version(),
        );

        return new JsonResponse(ReservaJson::de($reserva));
    }

    /** `POST /api/reservas/{id}/finalizar` (R6, O5). Solo ADMIN. */
    public function finalizar(FinalizarReservaRequest $request, string $id, FinalizarReserva $finalizarReserva): JsonResponse
    {
        $reserva = $finalizarReserva->ejecutar(strtolower($id), $request->conDanios(), $request->version());

        return new JsonResponse(ReservaJson::de($reserva));
    }

    private static function solicitante(Request $request): Solicitante
    {
        $solicitante = $request->attributes->get('solicitante');
        if (! $solicitante instanceof Solicitante) {
            throw new \LogicException('La ruta no pasó por el middleware jwt');
        }

        return $solicitante;
    }
}
