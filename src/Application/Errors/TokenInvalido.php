<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Errors;

/** El token falta, está mal firmado, venció o no es del emisor esperado. */
final class TokenInvalido extends \RuntimeException {}
