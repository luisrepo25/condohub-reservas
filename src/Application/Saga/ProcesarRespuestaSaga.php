<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Saga;

use CondoHub\Reservas\Application\Port\EventosProcesados;
use CondoHub\Reservas\Application\Port\InvitadosParaPases;
use CondoHub\Reservas\Application\Port\Outbox;
use CondoHub\Reservas\Application\Port\Reloj;
use CondoHub\Reservas\Application\Port\Transaccion;
use CondoHub\Reservas\Application\Support\Uuid;
use CondoHub\Reservas\Domain\Event\EmitirPasesReserva;
use CondoHub\Reservas\Domain\Model\SagaReserva;
use CondoHub\Reservas\Domain\Port\ParametroRepository;
use CondoHub\Reservas\Domain\Port\SagaRepository;

/**
 * Orquestador de la saga (S1–S5), lado de las respuestas: avanza la saga con lo que contestan Gestión y Acceso.
 * Todo en una transacción: el mensaje queda procesado (S5), la saga y la reserva cambian y los comandos siguientes
 * van al outbox (S4). Una respuesta que la saga ya no espera (tardía, de una cancelación) solo queda anotada.
 */
final class ProcesarRespuestaSaga
{
    public const AVANZO = 'avanzó';

    public const IGNORADA = 'no la esperaba';

    public const REPETIDA = 'repetida';

    public function __construct(
        private readonly SagaRepository $sagas,
        private readonly EventosProcesados $procesados,
        private readonly InvitadosParaPases $invitados,
        private readonly ParametroRepository $parametros,
        private readonly Outbox $outbox,
        private readonly Transaccion $transaccion,
        private readonly Reloj $reloj,
        private readonly AccionesDeSaga $acciones,
    ) {}

    /** @return string qué pasó: AVANZO, IGNORADA o REPETIDA (para el log) */
    public function ejecutar(RespuestaSaga $respuesta): string
    {
        /** @var string */
        return $this->transaccion->ejecutar(function () use ($respuesta): string {
            if (! $this->procesados->registrar($respuesta->eventId, $respuesta->tipo)) {
                return self::REPETIDA;
            }
            $saga = ($respuesta->sagaId !== null ? $this->sagas->porIdParaActualizar($respuesta->sagaId) : null)
                ?? $this->sagas->deLaReservaParaActualizar($respuesta->reservaId);
            $nueva = $saga === null ? null : $this->avanzar($saga, $respuesta);
            if ($nueva === null) {
                return self::IGNORADA;
            }
            $this->sagas->actualizar($nueva);

            return self::AVANZO;
        });
    }

    private function avanzar(SagaReserva $saga, RespuestaSaga $r): ?SagaReserva
    {
        $ahora = $this->reloj->ahora();

        return match (true) {
            $r->tipo === RespuestaSaga::CARGO_APLICADO && $saga->esperaCargos() => $this->trasLosCargos($saga, $r->ids),
            $r->tipo === RespuestaSaga::PASE_EMITIDO && $saga->esperaPases() => $this->acciones->confirmar($saga->pasesEmitidos($r->ids, $ahora)),
            $r->tipo === RespuestaSaga::CARGO_ANULADO && $saga->esperaAnulacion() => $this->acciones->fallar($saga->cargosAnulados($ahora)),
            $r->tipo === RespuestaSaga::PASE_REVOCADO => $saga->pasesRevocados(),
            default => null,
        };
    }

    /**
     * Paso 2 hecho. Con invitados se pide a Acceso emitir sus pases (paso 3); sin invitados la saga termina bien.
     *
     * @param  list<string>  $cargoIds
     */
    private function trasLosCargos(SagaReserva $saga, array $cargoIds): SagaReserva
    {
        $ahora = $this->reloj->ahora();
        $espera = $this->parametros->esperaPasoSagaSegundos();
        $invitados = $this->invitados->deLaReserva($saga->reservaId);
        if ($invitados === []) {
            return $this->acciones->confirmar($saga->cargosAplicados($cargoIds, null, $ahora, $espera));
        }

        $comando = Uuid::v4();
        $enPases = $saga->cargosAplicados($cargoIds, $comando, $ahora, $espera);
        $codigo = $saga->unidadCodigo();
        if ($codigo === null) {
            // Saga creada antes de guardar el código de la unidad: Acceso no puede emitir los pases, se compensa ya.
            return $this->acciones->compensarPases($enPases, 'Falta el código de la unidad para emitir los pases');
        }
        $this->outbox->guardar(EmitirPasesReserva::de(
            $comando, $saga->id, $this->acciones->reserva($saga), $codigo, $invitados,
            $this->parametros->reserva()->zonaHoraria, $ahora,
        ));

        return $enPases;
    }
}
