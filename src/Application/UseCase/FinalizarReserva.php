<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\UseCase;

use CondoHub\Reservas\Application\Errors\ReservaNoEncontrada;
use CondoHub\Reservas\Application\Port\Outbox;
use CondoHub\Reservas\Application\Port\Reloj;
use CondoHub\Reservas\Application\Port\Transaccion;
use CondoHub\Reservas\Application\Support\Uuid;
use CondoHub\Reservas\Domain\Event\AnularCargosReserva;
use CondoHub\Reservas\Domain\Model\Reserva;
use CondoHub\Reservas\Domain\Port\ReservaRepository;
use CondoHub\Reservas\Domain\Port\SagaRepository;

/**
 * R6: el ADMIN marca una reserva CONFIRMADA como FINALIZADA, indicando si hubo daños. Sin daños se pide a Gestión
 * devolver la garantía (nota de crédito); con daños, la garantía se queda. Solo ADMIN (lo exige la ruta).
 */
final class FinalizarReserva
{
    public function __construct(
        private readonly ReservaRepository $reservas,
        private readonly SagaRepository $sagas,
        private readonly Outbox $outbox,
        private readonly Transaccion $transaccion,
        private readonly Reloj $reloj,
    ) {}

    public function ejecutar(string $reservaId, bool $conDanios, int $version): Reserva
    {
        $reserva = $this->reservas->porId($reservaId) ?? throw new ReservaNoEncontrada("La reserva {$reservaId} no existe");

        $this->transaccion->ejecutar(function () use ($reserva, $conDanios, $version): void {
            $alcance = $reserva->finalizar($conDanios, $version);
            $this->reservas->actualizar($reserva, $version);
            if ($alcance !== null) {
                $this->outbox->guardar(AnularCargosReserva::de(
                    Uuid::v4(), $this->sagas->idDeLaReserva($reserva->id), $reserva, $alcance, $this->reloj->ahora(),
                ));
            }
        });

        return $reserva;
    }
}
