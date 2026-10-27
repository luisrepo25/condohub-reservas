<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Errors;

/**
 * Gestión no respondió (caída, tiempo agotado) o respondió algo que no cumple su contrato. HTTP 503: sin saber la
 * morosidad (R4) no se reserva, y el cliente puede reintentar con la misma Idempotency-Key.
 */
final class FinanzasNoDisponible extends \RuntimeException {}
