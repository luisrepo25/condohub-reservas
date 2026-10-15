<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Port;

use CondoHub\Reservas\Application\Errors\ServicioDeIdentidadNoDisponible;
use CondoHub\Reservas\Application\Errors\TokenInvalido;
use CondoHub\Reservas\Domain\Solicitante;

interface VerificadorToken
{
    /**
     * @throws TokenInvalido
     * @throws ServicioDeIdentidadNoDisponible
     */
    public function verificar(string $token): Solicitante;
}
