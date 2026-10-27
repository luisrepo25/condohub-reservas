<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Port;

/** Unidad de trabajo: todo lo que hace `$trabajo` se confirma junto o no se confirma (S4: cambio + mensaje). */
interface Transaccion
{
    /**
     * @template T
     *
     * @param  callable(): T  $trabajo
     * @return T
     */
    public function ejecutar(callable $trabajo): mixed;
}
