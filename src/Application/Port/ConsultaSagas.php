<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Port;

use CondoHub\Reservas\Application\UseCase\SagaPorRevisar;

/** Consultas de lectura sobre las sagas (sin pasar por el agregado: solo muestran datos). */
interface ConsultaSagas
{
    /**
     * S3: sagas cuya compensación no se logró y esperan a una persona, de la más antigua a la más reciente.
     *
     * @return list<SagaPorRevisar>
     */
    public function requierenRevision(): array;
}
