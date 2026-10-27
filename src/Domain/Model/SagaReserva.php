<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Model;

/**
 * Agregado SagaReserva (S1–S3): pasos de la saga de una reserva, persistidos en `saga_instancia` y `saga_paso` para
 * retomarla tras un reinicio. Nace al solicitar la reserva: el paso 1 (bloquear horario) ya está hecho en la misma
 * transacción y el paso 2 (cargos en Gestión) queda en curso con su comando en el outbox. El orquestador que avanza los
 * pasos con las respuestas (CargoAplicado, PaseEmitido) y compensa es de la Fase 3.
 */
final readonly class SagaReserva
{
    public const PASO_BLOQUEAR_HORARIO = 1;

    public const PASO_APLICAR_CARGOS = 2;

    public const PASO_EMITIR_PASES = 3;

    public const PASO_NOTIFICAR = 4;

    /** Nombres de `saga_paso.nombre` por número de paso. */
    public const NOMBRES = [
        self::PASO_BLOQUEAR_HORARIO => 'BLOQUEAR_HORARIO',
        self::PASO_APLICAR_CARGOS => 'APLICAR_CARGOS',
        self::PASO_EMITIR_PASES => 'EMITIR_PASES',
        self::PASO_NOTIFICAR => 'NOTIFICAR',
    ];

    private function __construct(
        public string $id,
        public string $reservaId,
        public string $estado,
        public int $pasoActual,
        /** S2: cuándo el orquestador revisa si el paso actual respondió (espera de la respuesta) */
        public \DateTimeImmutable $proximoReintento,
        /** eventId del comando del paso en curso (correlación con la respuesta) */
        public string $comandoEnCursoId,
    ) {}

    /**
     * S1: la saga empieza con el horario ya bloqueado (paso 1) y el comando del paso 2 enviado por el outbox.
     *
     * @param  int  $esperaSegundos  parámetro `saga.timeout_paso_segundos`
     */
    public static function iniciar(
        string $id,
        string $reservaId,
        string $comandoAplicarCargosId,
        \DateTimeImmutable $ahora,
        int $esperaSegundos,
    ): self {
        if ($esperaSegundos <= 0) {
            throw new \InvalidArgumentException('S2: la espera de un paso de la saga debe ser positiva');
        }

        return new self(
            $id,
            $reservaId,
            'EN_CURSO',
            self::PASO_APLICAR_CARGOS,
            $ahora->modify("+{$esperaSegundos} seconds"),
            $comandoAplicarCargosId,
        );
    }

    /** Estado inicial de cada paso: 1 hecho, 2 en curso, 3 y 4 pendientes. */
    public function estadoDelPaso(int $numero): string
    {
        return match (true) {
            $numero < $this->pasoActual => 'OK',
            $numero === $this->pasoActual => 'EN_CURSO',
            default => 'PENDIENTE',
        };
    }
}
