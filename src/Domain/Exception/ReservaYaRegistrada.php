<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Exception;

/**
 * O3: otra petición con la misma Idempotency-Key guardó su reserva primero (dos reintentos simultáneos). No es un error
 * para el cliente: el caso de uso responde con la reserva que quedó guardada.
 */
final class ReservaYaRegistrada extends \RuntimeException {}
