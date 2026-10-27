<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Errors;

/**
 * O3: la Idempotency-Key ya la usó otra persona en otra solicitud. No se devuelve esa reserva ajena: HTTP 409.
 * (Una clave UUID v4 generada en el dispositivo solo se repite por error o mala intención.)
 */
final class ClaveIdempotenciaUsada extends \RuntimeException {}
