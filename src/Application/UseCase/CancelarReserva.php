<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\UseCase;

use CondoHub\Reservas\Application\AccesoAReservas;
use CondoHub\Reservas\Application\Errors\ClaveIdempotenciaUsada;
use CondoHub\Reservas\Application\Errors\ReservaNoEncontrada;
use CondoHub\Reservas\Application\Port\Outbox;
use CondoHub\Reservas\Application\Port\Reloj;
use CondoHub\Reservas\Application\Port\Transaccion;
use CondoHub\Reservas\Application\Support\Uuid;
use CondoHub\Reservas\Domain\Event\AnularCargosReserva;
use CondoHub\Reservas\Domain\Exception\VersionDesactualizada;
use CondoHub\Reservas\Domain\Model\Reserva;
use CondoHub\Reservas\Domain\Port\ParametroRepository;
use CondoHub\Reservas\Domain\Port\ReservaRepository;
use CondoHub\Reservas\Domain\Port\SagaRepository;
use CondoHub\Reservas\Domain\Solicitante;
use CondoHub\Reservas\Domain\ValueObject\IdempotencyKey;

/**
 * R7: el residente (o el ADMIN) cancela una reserva CONFIRMADA. Con 48 h o más de anticipación se anulan todos los
 * cargos; si no, se cobra el uso y se devuelve la garantía. La reserva y el pedido a Gestión (AnularCargosReserva) se
 * guardan en una transacción (S4). Repetir la misma Idempotency-Key devuelve la misma reserva cancelada (O3).
 */
final class CancelarReserva
{
    public function __construct(
        private readonly ReservaRepository $reservas,
        private readonly SagaRepository $sagas,
        private readonly ParametroRepository $parametros,
        private readonly AccesoAReservas $acceso,
        private readonly Outbox $outbox,
        private readonly Transaccion $transaccion,
        private readonly Reloj $reloj,
    ) {}

    public function ejecutar(Solicitante $solicitante, string $reservaId, string $idempotencyKey, ?string $motivo, int $version): Reserva
    {
        $clave = IdempotencyKey::de($idempotencyKey);
        $repetida = $this->repetida($clave, $reservaId, $solicitante);
        if ($repetida !== null) {
            return $repetida;
        }

        $reserva = $this->reservas->porId($reservaId) ?? throw new ReservaNoEncontrada("La reserva {$reservaId} no existe");
        $this->acceso->exigirPuedeOperar($solicitante, $reserva);
        $parametros = $this->parametros->reserva();

        try {
            $this->transaccion->ejecutar(function () use ($reserva, $clave, $motivo, $version, $parametros): void {
                $ahora = $this->reloj->ahora();
                $alcance = $reserva->cancelar($ahora, $motivo, $version, $parametros);
                $this->reservas->actualizar($reserva, $version, $clave);
                $sagaId = $this->sagas->idDeLaReserva($reserva->id);
                $this->outbox->guardar(AnularCargosReserva::de(Uuid::v4(), $sagaId, $reserva, $alcance, $ahora));
            });
        } catch (VersionDesactualizada $e) {
            // Un reintento simultáneo con la misma clave pudo cancelarla primero: entonces es la misma respuesta (O3).
            return $this->repetida($clave, $reservaId, $solicitante) ?? throw $e;
        }

        return $reserva;
    }

    /** O3: la reserva ya cancelada con esta clave; la clave usada en otra reserva es un error. */
    private function repetida(IdempotencyKey $clave, string $reservaId, Solicitante $solicitante): ?Reserva
    {
        $cancelada = $this->reservas->porClaveCancelacion($clave);
        if ($cancelada === null) {
            return null;
        }
        if ($cancelada->id !== $reservaId) {
            throw new ClaveIdempotenciaUsada('Esa Idempotency-Key ya se usó para cancelar otra reserva');
        }
        $this->acceso->exigirPuedeOperar($solicitante, $cancelada);

        return $cancelada;
    }
}
