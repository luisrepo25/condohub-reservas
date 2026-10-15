<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application;

use CondoHub\Reservas\Application\Errors\SinPermiso;
use CondoHub\Reservas\Domain\Rol;
use CondoHub\Reservas\Domain\Solicitante;

/** Autorización por rol del token: cada endpoint declara qué roles lo pueden usar. */
final class Autorizacion
{
    public static function exigirAlgunRol(Solicitante $solicitante, Rol ...$permitidos): void
    {
        if (! $solicitante->tieneAlgunRol(...$permitidos)) {
            $nombres = implode(', ', array_map(static fn (Rol $r): string => $r->value, $permitidos));
            throw new SinPermiso("Se requiere alguno de los roles: {$nombres}");
        }
    }
}
