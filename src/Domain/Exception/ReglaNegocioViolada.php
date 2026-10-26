<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Exception;

/** Una regla de negocio impide la operación; `codigo` es el de reglas-de-negocio.md (R2, R3...). HTTP: 422. */
final class ReglaNegocioViolada extends \DomainException
{
    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
