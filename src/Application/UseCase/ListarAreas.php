<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\UseCase;

use CondoHub\Reservas\Domain\Model\AreaComun;
use CondoHub\Reservas\Domain\Port\AreaComunRepository;

/** R1: catálogo de áreas comunes reservables. Lo ve cualquier rol autenticado (la ruta exige el JWT). */
final class ListarAreas
{
    public function __construct(private readonly AreaComunRepository $areas) {}

    /** @return list<AreaComun> */
    public function ejecutar(): array
    {
        return $this->areas->activas();
    }
}
