<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Port;

use CondoHub\Reservas\Domain\Model\AreaComun;

/** Repositorio del agregado AreaComun (R1). Las áreas son datos de referencia: se cargan con la migración. */
interface AreaComunRepository
{
    /** @return list<AreaComun> áreas activas, ordenadas por código */
    public function activas(): array;
}
