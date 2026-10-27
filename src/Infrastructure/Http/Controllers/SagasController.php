<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Http\Controllers;

use CondoHub\Reservas\Application\UseCase\ListarSagasPorRevisar;
use CondoHub\Reservas\Application\UseCase\SagaPorRevisar;
use Illuminate\Http\JsonResponse;

/** `GET /api/sagas/requieren-revision` (S3). Solo ADMIN: lo exige la ruta. Forma `Saga` del contrato. */
final class SagasController
{
    public function __invoke(ListarSagasPorRevisar $listar): JsonResponse
    {
        return new JsonResponse(array_map(static fn (SagaPorRevisar $s): array => [
            'id' => $s->id,
            'reservaId' => $s->reservaId,
            'estado' => $s->estado,
            'pasoActual' => $s->pasoActual,
            'ultimoError' => $s->ultimoError,
        ], $listar->ejecutar()));
    }
}
