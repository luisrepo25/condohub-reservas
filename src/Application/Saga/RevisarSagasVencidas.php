<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Saga;

use CondoHub\Reservas\Application\Errors\ReservaNoEncontrada;
use CondoHub\Reservas\Application\Port\Reloj;
use CondoHub\Reservas\Application\Port\Transaccion;
use CondoHub\Reservas\Domain\Model\SagaReserva;
use CondoHub\Reservas\Domain\Port\SagaRepository;

/**
 * Orquestador de la saga (S1–S3), lado de los plazos: revisa las sagas cuyo paso no respondió a tiempo y las compensa.
 * Al arrancar retoma las que quedaron pendientes (S1): su plazo ya venció. Cada saga va en su propia transacción;
 * una que no se puede compensar por sus datos pasa a REQUIERE_REVISION (S3) en lugar de detener al resto.
 */
final class RevisarSagasVencidas
{
    public function __construct(
        private readonly SagaRepository $sagas,
        private readonly AccionesDeSaga $acciones,
        private readonly Transaccion $transaccion,
        private readonly Reloj $reloj,
    ) {}

    /** @return int cuántas sagas revisó */
    public function ejecutar(int $limite = 20): int
    {
        $revisadas = 0;
        while ($revisadas < $limite && $this->revisarUna()) {
            $revisadas++;
        }

        return $revisadas;
    }

    private function revisarUna(): bool
    {
        /** @var bool */
        return $this->transaccion->ejecutar(function (): bool {
            $saga = $this->sagas->siguienteVencida($this->reloj->ahora());
            if ($saga === null) {
                return false;
            }
            try {
                // Punto de guardado: si la compensación choca con los datos, se deshace solo ella.
                $nueva = $this->transaccion->ejecutar(fn (): SagaReserva => $this->compensar($saga));
            } catch (\DomainException|ReservaNoEncontrada $e) {
                // Sin esto, una saga inconsistente se reintentaría siempre y frenaría a las demás.
                $nueva = $saga->aRevision("No se pudo compensar: {$e->getMessage()}", $this->reloj->ahora());
            }
            $this->sagas->actualizar($nueva);

            return true;
        });
    }

    private function compensar(SagaReserva $saga): SagaReserva
    {
        return match (true) {
            $saga->esperaCargos() => $this->acciones->liberarSinCargos($saga),
            $saga->esperaPases() => $this->acciones->compensarPases($saga, 'Acceso no emitió los pases de los invitados a tiempo'),
            default => $this->acciones->reintentarAnulacion($saga),
        };
    }
}
