<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain;

/** Quien hace la petición: el `sub` del token de Keycloak y sus roles. */
final readonly class Solicitante
{
    /** @param list<Rol> $roles */
    public function __construct(public string $sub, public array $roles) {}

    public function tieneAlgunRol(Rol ...$permitidos): bool
    {
        foreach ($permitidos as $rol) {
            if (in_array($rol, $this->roles, true)) {
                return true;
            }
        }

        return false;
    }
}
