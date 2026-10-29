<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Port;

use CondoHub\Reservas\Domain\ValueObject\ParametrosReserva;

/** Parámetros de negocio de la tabla `parametro` (R2–R4, R7, S2): nunca constantes en el código. */
interface ParametroRepository
{
    public function reserva(): ParametrosReserva;

    /** S2: segundos que se espera la respuesta de cada paso de la saga (`saga.timeout_paso_segundos`). */
    public function esperaPasoSagaSegundos(): int;

    /** S3: intentos de una compensación antes de dejar la saga en REQUIERE_REVISION (`saga.reintentos_por_paso`). */
    public function reintentosPorPasoSaga(): int;
}
