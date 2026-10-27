<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Persistence;

use CondoHub\Reservas\Application\Port\ConsultaSagas;
use CondoHub\Reservas\Application\UseCase\SagaPorRevisar;
use Illuminate\Database\ConnectionInterface;

/** ConsultaSagas sobre `saga_instancia` (índice parcial `ix_saga_revision`). */
final class PgConsultaSagas implements ConsultaSagas
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function requierenRevision(): array
    {
        $filas = $this->db->select(
            "SELECT id, reserva_id, estado, paso_actual, ultimo_error
               FROM saga_instancia
              WHERE estado = 'REQUIERE_REVISION'
              ORDER BY actualizada_en, id",
        );

        return array_values(array_map(static function (object $f): SagaPorRevisar {
            /** @var object{id: string, reserva_id: string, estado: string, paso_actual: int, ultimo_error: ?string} $f */
            return new SagaPorRevisar($f->id, $f->reserva_id, $f->estado, (int) $f->paso_actual, $f->ultimo_error);
        }, $filas));
    }
}
