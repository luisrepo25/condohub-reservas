<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Messaging;

/** Un mensaje de la cola de la saga que no cumple su contrato: no se reintenta, va a la cola de fallidos. */
final class MensajeSagaInvalido extends \RuntimeException {}
