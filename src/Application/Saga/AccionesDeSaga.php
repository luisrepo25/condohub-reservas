<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Saga;

use CondoHub\Reservas\Application\Errors\ReservaNoEncontrada;
use CondoHub\Reservas\Application\Port\Outbox;
use CondoHub\Reservas\Application\Port\Reloj;
use CondoHub\Reservas\Application\Support\Uuid;
use CondoHub\Reservas\Domain\Event\AnularCargosReserva;
use CondoHub\Reservas\Domain\Event\ReservaConfirmada;
use CondoHub\Reservas\Domain\Event\ReservaFallida;
use CondoHub\Reservas\Domain\Event\RevocarPasesReserva;
use CondoHub\Reservas\Domain\Model\Reserva;
use CondoHub\Reservas\Domain\Model\SagaReserva;
use CondoHub\Reservas\Domain\Port\AreaComunRepository;
use CondoHub\Reservas\Domain\Port\ParametroRepository;
use CondoHub\Reservas\Domain\Port\ReservaRepository;
use CondoHub\Reservas\Domain\ValueObject\AlcanceAnulacion;

/**
 * Lo que el orquestador hace al cerrar o compensar una saga, compartido por las respuestas y por las esperas vencidas:
 * cambia la reserva (R8) y deja los mensajes en el outbox (S4). Se llama dentro de la transacción del caso de uso.
 */
final class AccionesDeSaga
{
    public function __construct(
        private readonly ReservaRepository $reservas,
        private readonly AreaComunRepository $areas,
        private readonly ParametroRepository $parametros,
        private readonly Outbox $outbox,
        private readonly Reloj $reloj,
    ) {}

    public function reserva(SagaReserva $saga): Reserva
    {
        return $this->reservas->porId($saga->reservaId)
            ?? throw new ReservaNoEncontrada("La reserva {$saga->reservaId} de la saga {$saga->id} no existe");
    }

    /** Paso 4: la reserva queda CONFIRMADA y ReservaConfirmada avisa al residente (n8n). */
    public function confirmar(SagaReserva $terminada): SagaReserva
    {
        $reserva = $this->reserva($terminada);
        $version = $reserva->version();
        $reserva->confirmar();
        $this->reservas->actualizar($reserva, $version);
        $area = $this->areas->porId($reserva->areaId);
        $this->outbox->guardar(ReservaConfirmada::de(
            Uuid::v4(), $terminada->id, $reserva, $area->nombre ?? $reserva->areaId, $this->reloj->ahora(),
        ));

        return $terminada;
    }

    /** S2: la saga quedó compensada; la reserva pasa a FALLIDA (libera el horario) y se avisa al residente. */
    public function fallar(SagaReserva $compensada): SagaReserva
    {
        $reserva = $this->reserva($compensada);
        $version = $reserva->version();
        $reserva->fallar($compensada->ultimoError ?? 'La reserva no se pudo completar');
        $this->reservas->actualizar($reserva, $version);
        $this->outbox->guardar(ReservaFallida::de(Uuid::v4(), $compensada->id, $reserva, $this->reloj->ahora()));

        return $compensada;
    }

    /**
     * S2, paso 2 vencido: la anulación se envía sin esperarla (Gestión la procesa después del comando que quizá aplique)
     * y la reserva falla ya.
     */
    public function liberarSinCargos(SagaReserva $enCargos): SagaReserva
    {
        $reserva = $this->reserva($enCargos);
        $this->outbox->guardar(AnularCargosReserva::de(
            Uuid::v4(), $enCargos->id, $reserva, AlcanceAnulacion::Todos, $this->reloj->ahora(),
        ));

        return $this->fallar($enCargos->cargosNoAplicados($this->reloj->ahora()));
    }

    /**
     * S2, paso 3 fallido: revoca los pases sin esperar (Acceso la procesa después de la emisión pendiente) y anula los
     * cargos ya aplicados esperando la confirmación (S3).
     */
    public function compensarPases(SagaReserva $enPases, string $motivo): SagaReserva
    {
        $ahora = $this->reloj->ahora();
        $anular = Uuid::v4();
        $this->outbox->guardar(RevocarPasesReserva::de(Uuid::v4(), $enPases->id, $enPases->reservaId, $ahora));
        $this->outbox->guardar(AnularCargosReserva::de(
            $anular, $enPases->id, $this->reserva($enPases), AlcanceAnulacion::Todos, $ahora,
        ));

        return $enPases->pasesNoEmitidos($anular, $ahora, $this->parametros->esperaPasoSagaSegundos(), $motivo);
    }

    /** S3: reenvía la anulación con espera exponencial o, agotados los intentos, deja la saga en REQUIERE_REVISION. */
    public function reintentarAnulacion(SagaReserva $compensando): SagaReserva
    {
        $ahora = $this->reloj->ahora();
        $anular = Uuid::v4();
        $nueva = $compensando->reintentarAnulacion(
            $anular, $ahora, $this->parametros->esperaPasoSagaSegundos(), $this->parametros->reintentosPorPasoSaga(),
        );
        if ($nueva->estado === SagaReserva::COMPENSANDO) {
            $this->outbox->guardar(AnularCargosReserva::de(
                $anular, $compensando->id, $this->reserva($compensando), AlcanceAnulacion::Todos, $ahora,
            ));
        }

        return $nueva;
    }
}
