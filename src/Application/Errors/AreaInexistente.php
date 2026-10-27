<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Errors;

/** El área pedida no existe en el catálogo (R1). HTTP 422. */
final class AreaInexistente extends \RuntimeException {}
