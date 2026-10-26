<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Http\Controllers;

use CondoHub\Reservas\Application\UseCase\ListarAreas;
use CondoHub\Reservas\Domain\Model\AreaComun;
use Illuminate\Http\JsonResponse;

/** `GET /api/areas` (R1). Sin lógica de negocio: llama al caso de uso y da la forma del contrato (`AreaComun`). */
final class AreasController
{
    public function __invoke(ListarAreas $listarAreas): JsonResponse
    {
        return new JsonResponse(array_map(self::json(...), $listarAreas->ejecutar()));
    }

    /** @return array<string, string|int> */
    public static function json(AreaComun $area): array
    {
        return [
            'id' => $area->id,
            'codigo' => $area->codigo,
            'nombre' => $area->nombre,
            'tipo' => $area->tipo->value,
            'aforo' => $area->aforo,
            'horaApertura' => $area->horaApertura,
            'horaCierre' => $area->horaCierre,
            'bloqueMinimoHoras' => $area->bloqueMinimoHoras,
            'costoUso' => (string) $area->costoUso,
            'garantia' => (string) $area->garantia,
        ];
    }
}
