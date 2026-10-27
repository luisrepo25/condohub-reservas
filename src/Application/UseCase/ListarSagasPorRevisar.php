<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\UseCase;

use CondoHub\Reservas\Application\Port\ConsultaSagas;

/** S3: `GET /api/sagas/requieren-revision` para la consola del ADMIN (solo ADMIN: lo exige la ruta). */
final class ListarSagasPorRevisar
{
    public function __construct(private readonly ConsultaSagas $sagas) {}

    /** @return list<SagaPorRevisar> */
    public function ejecutar(): array
    {
        return $this->sagas->requierenRevision();
    }
}
