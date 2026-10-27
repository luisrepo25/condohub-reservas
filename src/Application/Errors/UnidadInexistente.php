<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Errors;

/** La unidad pedida no existe en Finanzas (Gestión respondió NOT_FOUND). HTTP 422. */
final class UnidadInexistente extends \RuntimeException {}
