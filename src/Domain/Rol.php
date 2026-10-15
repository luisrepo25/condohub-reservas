<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain;

/** Roles del realm `condohub` de Keycloak. No hay otros. */
enum Rol: string
{
    case Admin = 'ADMIN';
    case Residente = 'RESIDENTE';
    case Guardia = 'GUARDIA';
}
