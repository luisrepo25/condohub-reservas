<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Persistence;

use CondoHub\Reservas\Application\Port\InvitadosParaPases;
use Illuminate\Database\ConnectionInterface;

/** InvitadosParaPases sobre `invitado_reserva`: el id de cada fila es el `invitadoId` del contrato con Acceso. */
final class PgInvitadosParaPases implements InvitadosParaPases
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function deLaReserva(string $reservaId): array
    {
        $filas = $this->db->select(
            'SELECT id, nombre FROM invitado_reserva WHERE reserva_id = ?::uuid ORDER BY nombre, id',
            [$reservaId],
        );

        return array_values(array_map(static function (object $f): array {
            /** @var object{id: string, nombre: string} $f */
            return ['invitadoId' => $f->id, 'nombre' => $f->nombre];
        }, $filas));
    }
}
