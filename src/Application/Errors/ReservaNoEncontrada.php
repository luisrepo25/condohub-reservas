<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Errors;

/** La reserva pedida no existe. HTTP 404. */
final class ReservaNoEncontrada extends \RuntimeException {}
