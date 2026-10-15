<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Http;

use Illuminate\Http\JsonResponse;

/** Errores en `application/problem+json` (RFC 7807); `code` = código de la regla cuando aplica. */
final class Problema
{
    /** @param array<string, string> $cabeceras */
    public static function de(int $status, string $titulo, ?string $detalle = null, ?string $code = null, array $cabeceras = []): JsonResponse
    {
        $cuerpo = ['type' => 'about:blank', 'title' => $titulo, 'status' => $status];
        if ($detalle !== null) {
            $cuerpo['detail'] = $detalle;
        }
        if ($code !== null) {
            $cuerpo['code'] = $code;
        }

        return new JsonResponse($cuerpo, $status, ['Content-Type' => 'application/problem+json', ...$cabeceras]);
    }

    public static function noImplementado(string $que): JsonResponse
    {
        return self::de(501, 'No implementado', "{$que} aún no está implementado", 'NO_IMPLEMENTADO');
    }
}
