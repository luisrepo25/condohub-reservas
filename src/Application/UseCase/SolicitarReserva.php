<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\UseCase;

use CondoHub\Reservas\Application\Errors\AreaInexistente;
use CondoHub\Reservas\Application\Errors\ClaveIdempotenciaUsada;
use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Application\Port\Outbox;
use CondoHub\Reservas\Application\Port\Reloj;
use CondoHub\Reservas\Application\Port\Transaccion;
use CondoHub\Reservas\Application\Support\Uuid;
use CondoHub\Reservas\Domain\Event\AplicarCargosReserva;
use CondoHub\Reservas\Domain\Exception\ReservaYaRegistrada;
use CondoHub\Reservas\Domain\Model\AreaComun;
use CondoHub\Reservas\Domain\Model\Reserva;
use CondoHub\Reservas\Domain\Model\SagaReserva;
use CondoHub\Reservas\Domain\Port\AreaComunRepository;
use CondoHub\Reservas\Domain\Port\ParametroRepository;
use CondoHub\Reservas\Domain\Port\ReservaRepository;
use CondoHub\Reservas\Domain\Port\SagaRepository;
use CondoHub\Reservas\Domain\Service\PoliticaMorosidad;
use CondoHub\Reservas\Domain\Service\VerificadorDisponibilidad;
use CondoHub\Reservas\Domain\Solicitante;
use CondoHub\Reservas\Domain\ValueObject\BloqueHorario;
use CondoHub\Reservas\Domain\ValueObject\IdempotencyKey;
use CondoHub\Reservas\Domain\ValueObject\ParametrosReserva;

/**
 * Solicitar una reserva = paso 1 de la saga (R1–R5, O3, S1, S4). Orden pensado para no dejar nada a medias:
 *  1. O3: una clave repetida devuelve la reserva que ya existe, sin volver a validar ni consultar.
 *  2. R4 y F1 con Gestión, fuera de la transacción (no se retiene la base mientras se espera la red).
 *  3. En UNA transacción, con la unidad bloqueada (R3 sin carreras): reglas del dominio, horario libre (R2),
 *     reserva SOLICITADA + saga (S1) + comando AplicarCargosReserva en el outbox (S4).
 * Si dos reintentos con la misma clave llegan a la vez, el segundo responde con la reserva del primero.
 */
final class SolicitarReserva
{
    public function __construct(
        private readonly ReservaRepository $reservas,
        private readonly AreaComunRepository $areas,
        private readonly SagaRepository $sagas,
        private readonly ParametroRepository $parametros,
        private readonly FinanzasGateway $finanzas,
        private readonly Outbox $outbox,
        private readonly Transaccion $transaccion,
        private readonly Reloj $reloj,
        private readonly PoliticaMorosidad $politicaMorosidad,
        private readonly VerificadorDisponibilidad $disponibilidad,
    ) {}

    public function ejecutar(Solicitante $solicitante, SolicitudReserva $solicitud): ResultadoSolicitud
    {
        $clave = IdempotencyKey::de($solicitud->idempotencyKey);
        $existente = $this->reservas->porIdempotencyKey($clave);
        if ($existente !== null) {
            return $this->repetida($existente, $solicitante);
        }

        $area = $this->areas->porId($solicitud->areaId) ?? throw new AreaInexistente("El área {$solicitud->areaId} no existe");
        $bloque = new BloqueHorario($solicitud->inicio, $solicitud->fin);
        $parametros = $this->parametros->reserva();
        $this->politicaMorosidad->exigirPuedeReservar($this->finanzas->expensasVencidas($solicitud->unidadId), $parametros);

        try {
            $reserva = $this->transaccion->ejecutar(fn (): Reserva => $this->registrar($solicitante, $solicitud, $clave, $area, $bloque, $parametros));
        } catch (ReservaYaRegistrada) {
            $ganadora = $this->reservas->porIdempotencyKey($clave)
                ?? throw new \LogicException('O3: la reserva con la clave repetida no aparece');

            return $this->repetida($ganadora, $solicitante);
        }

        return new ResultadoSolicitud($reserva, false);
    }

    private function registrar(
        Solicitante $solicitante,
        SolicitudReserva $solicitud,
        IdempotencyKey $clave,
        AreaComun $area,
        BloqueHorario $bloque,
        ParametrosReserva $parametros,
    ): Reserva {
        $ahora = $this->reloj->ahora();
        $activas = $this->reservas->contarActivasBloqueandoUnidad($solicitud->unidadId);
        // O3: con la unidad ya bloqueada, un reintento simultáneo que llegó primero ya es visible: se responde con ese.
        if ($this->reservas->porIdempotencyKey($clave) !== null) {
            throw new ReservaYaRegistrada('Un reintento con la misma Idempotency-Key se registró mientras tanto');
        }
        $reserva = Reserva::solicitar(
            Uuid::v4(),
            $area,
            $solicitud->unidadId,
            $solicitante->sub,
            $bloque,
            $solicitud->invitados,
            $clave,
            $ahora,
            $activas,
            $parametros,
        );
        $this->disponibilidad->exigirLibre($bloque, $this->reservas->bloquesOcupados($area->id, $bloque));
        $this->reservas->guardarNueva($reserva);

        $comando = AplicarCargosReserva::de(Uuid::v4(), Uuid::v4(), $reserva, $area, $ahora);
        $this->sagas->guardarNueva(SagaReserva::iniciar(
            $comando->sagaId, $reserva->id, $comando->eventId, $ahora, $this->parametros->esperaPasoSagaSegundos(),
        ));
        $this->outbox->guardar($comando);

        return $reserva;
    }

    /** O3: la misma clave devuelve la misma reserva, solo a quien la creó. */
    private function repetida(Reserva $reserva, Solicitante $solicitante): ResultadoSolicitud
    {
        if ($reserva->solicitadaPor !== $solicitante->sub) {
            throw new ClaveIdempotenciaUsada('Esa Idempotency-Key ya se usó en otra solicitud');
        }

        return new ResultadoSolicitud($reserva, true);
    }
}
