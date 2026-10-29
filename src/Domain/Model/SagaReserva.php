<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Model;

/**
 * Agregado SagaReserva (S1–S3): pasos de la saga de una reserva, persistidos en `saga_instancia` y `saga_paso` para
 * retomarla tras un reinicio. Nace al solicitar la reserva: el paso 1 (bloquear horario) ya está hecho en la misma
 * transacción y el paso 2 (cargos en Gestión) queda en curso con su comando en el outbox.
 *
 * Inmutable: cada respuesta o espera vencida devuelve una saga nueva. Los reintentos de un paso los hace el consumidor
 * que lo ejecuta (3, con espera exponencial, S2); aquí cada paso tiene un plazo y, si vence, se compensa en orden
 * inverso. Solo se espera la compensación de lo confirmado (los cargos aplicados); la de lo que nunca respondió se
 * envía sin esperarla: RabbitMQ la entrega después del comando original en la misma cola, así que siempre lo deshace.
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

    public const EN_CURSO = 'EN_CURSO';

    public const COMPLETADA = 'COMPLETADA';

    public const COMPENSANDO = 'COMPENSANDO';

    public const COMPENSADA = 'COMPENSADA';

    public const REQUIERE_REVISION = 'REQUIERE_REVISION';

    /**
     * @param  array<string, mixed>  $contexto  datos que necesitan los pasos siguientes (código de la unidad, ids devueltos)
     * @param  array<int, string>  $pasos  estado explícito de cada paso; si falta, se deduce del paso actual
     */
    private function __construct(
        public string $id,
        public string $reservaId,
        public string $estado,
        public int $pasoActual,
        /** S2: cuándo el orquestador revisa si el paso actual respondió (espera de la respuesta) */
        public \DateTimeImmutable $proximoReintento,
        /** eventId del comando del paso en curso (correlación con la respuesta) */
        public string $comandoEnCursoId,
        public array $contexto = [],
        public array $pasos = [],
        /** S3: veces que se reenvió la anulación de los cargos */
        public int $intentosCompensacion = 0,
        public ?string $ultimoError = null,
    ) {}

    /**
     * S1: la saga empieza con el horario ya bloqueado (paso 1) y el comando del paso 2 enviado por el outbox.
     *
     * @param  int  $esperaSegundos  parámetro `saga.timeout_paso_segundos`
     * @param  array<string, mixed>  $contexto  datos para los pasos siguientes (ej. `unidadCodigo` para Acceso)
     */
    public static function iniciar(
        string $id,
        string $reservaId,
        string $comandoAplicarCargosId,
        \DateTimeImmutable $ahora,
        int $esperaSegundos,
        array $contexto = [],
    ): self {
        if ($esperaSegundos <= 0) {
            throw new \InvalidArgumentException('S2: la espera de un paso de la saga debe ser positiva');
        }

        return new self(
            $id,
            $reservaId,
            self::EN_CURSO,
            self::PASO_APLICAR_CARGOS,
            $ahora->modify("+{$esperaSegundos} seconds"),
            $comandoAplicarCargosId,
            $contexto,
        );
    }

    /**
     * Reconstruye una saga guardada (repositorio).
     *
     * @param  array<string, mixed>  $contexto
     * @param  array<int, string>  $pasos
     */
    public static function reconstituir(
        string $id,
        string $reservaId,
        string $estado,
        int $pasoActual,
        \DateTimeImmutable $proximoReintento,
        string $comandoEnCursoId,
        array $contexto,
        array $pasos,
        int $intentosCompensacion,
        ?string $ultimoError,
    ): self {
        return new self(
            $id, $reservaId, $estado, $pasoActual, $proximoReintento, $comandoEnCursoId, $contexto, $pasos,
            $intentosCompensacion, $ultimoError,
        );
    }

    /** Estado de cada paso: el guardado o, al iniciar, 1 hecho, 2 en curso, 3 y 4 pendientes. */
    public function estadoDelPaso(int $numero): string
    {
        return $this->pasos[$numero] ?? match (true) {
            $numero < $this->pasoActual => 'OK',
            $numero === $this->pasoActual => 'EN_CURSO',
            default => 'PENDIENTE',
        };
    }

    /** Código de la unidad para Acceso (paso 3), guardado al iniciar; null en sagas anteriores a este dato. */
    public function unidadCodigo(): ?string
    {
        $codigo = $this->contexto['unidadCodigo'] ?? null;

        return is_string($codigo) && $codigo !== '' ? $codigo : null;
    }

    public function esperaCargos(): bool
    {
        return $this->estado === self::EN_CURSO && $this->pasoActual === self::PASO_APLICAR_CARGOS;
    }

    public function esperaPases(): bool
    {
        return $this->estado === self::EN_CURSO && $this->pasoActual === self::PASO_EMITIR_PASES;
    }

    /** S3: espera la anulación de los cargos; también si ya pasó a revisión y la respuesta llega tarde. */
    public function esperaAnulacion(): bool
    {
        return $this->estado === self::COMPENSANDO
            || ($this->estado === self::REQUIERE_REVISION && $this->estadoDelPaso(self::PASO_APLICAR_CARGOS) === 'COMPENSACION_FALLIDA');
    }

    /** S2: el paso en curso (o la compensación) no respondió dentro de su plazo. */
    public function vencio(\DateTimeImmutable $ahora): bool
    {
        return in_array($this->estado, [self::EN_CURSO, self::COMPENSANDO], true) && $this->proximoReintento <= $ahora;
    }

    /**
     * Paso 2 hecho (Gestión respondió CargoAplicado). Si hay invitados sigue el paso 3 con su comando; si no, el paso 3
     * no aplica y la saga termina bien.
     *
     * @param  list<string>  $cargoIds
     * @param  string|null  $comandoPasesId  eventId de EmitirPasesReserva; null si la reserva no tiene invitados
     */
    public function cargosAplicados(array $cargoIds, ?string $comandoPasesId, \DateTimeImmutable $ahora, int $esperaSegundos): self
    {
        $this->exigir($this->esperaCargos(), 'CargoAplicado');
        $contexto = [...$this->contexto, 'cargoIds' => $cargoIds];
        $pasos = $this->pasosCon([self::PASO_APLICAR_CARGOS => 'OK']);
        if ($comandoPasesId === null) {
            return $this->con(self::COMPLETADA, self::PASO_NOTIFICAR, $ahora, $this->comandoEnCursoId, $contexto,
                array_replace($pasos, [self::PASO_EMITIR_PASES => 'OMITIDO', self::PASO_NOTIFICAR => 'OK']));
        }

        return $this->con(self::EN_CURSO, self::PASO_EMITIR_PASES, $ahora->modify("+{$esperaSegundos} seconds"),
            $comandoPasesId, $contexto, array_replace($pasos, [self::PASO_EMITIR_PASES => 'EN_CURSO']));
    }

    /**
     * Paso 3 hecho (Acceso respondió PaseEmitido): la saga terminó bien y el paso 4 es el aviso ReservaConfirmada.
     *
     * @param  list<string>  $codigosPases
     */
    public function pasesEmitidos(array $codigosPases, \DateTimeImmutable $ahora): self
    {
        $this->exigir($this->esperaPases(), 'PaseEmitido');

        return $this->con(self::COMPLETADA, self::PASO_NOTIFICAR, $ahora, $this->comandoEnCursoId,
            [...$this->contexto, 'pases' => $codigosPases],
            $this->pasosCon([self::PASO_EMITIR_PASES => 'OK', self::PASO_NOTIFICAR => 'OK']));
    }

    /**
     * S2: Gestión no aplicó los cargos a tiempo. Nada quedó confirmado: se envía la anulación sin esperarla (por si los
     * aplica después), se libera el horario y la saga termina compensada.
     */
    public function cargosNoAplicados(\DateTimeImmutable $ahora): self
    {
        $this->exigir($this->esperaCargos(), 'el vencimiento del paso 2');

        return $this->con(self::COMPENSADA, self::PASO_APLICAR_CARGOS, $ahora, $this->comandoEnCursoId, $this->contexto, $this->pasosCon([
            self::PASO_BLOQUEAR_HORARIO => 'COMPENSADO',
            self::PASO_APLICAR_CARGOS => 'FALLIDO',
        ]), 0, 'Gestión no aplicó los cargos de la reserva a tiempo');
    }

    /**
     * S2: Acceso no emitió los pases a tiempo. Se revocan sin esperar y se anulan los cargos ya aplicados, esperando su
     * confirmación (S3): recién con CargoAnulado la reserva queda FALLIDA.
     */
    public function pasesNoEmitidos(string $comandoAnularId, \DateTimeImmutable $ahora, int $esperaSegundos, string $motivo = 'Acceso no emitió los pases de los invitados a tiempo'): self
    {
        $this->exigir($this->esperaPases(), 'el vencimiento del paso 3');

        return $this->con(self::COMPENSANDO, self::PASO_APLICAR_CARGOS, $ahora->modify("+{$esperaSegundos} seconds"),
            $comandoAnularId, $this->contexto,
            $this->pasosCon([self::PASO_EMITIR_PASES => 'FALLIDO']), 0, $motivo);
    }

    /**
     * S3: la anulación no respondió a tiempo. Se reenvía con espera exponencial; agotados los intentos, la saga pasa a
     * REQUIERE_REVISION (consola del ADMIN) y la reserva sigue SOLICITADA hasta que alguien la resuelva.
     *
     * @param  int  $maxIntentos  parámetro `saga.reintentos_por_paso`
     */
    public function reintentarAnulacion(string $comandoAnularId, \DateTimeImmutable $ahora, int $esperaSegundos, int $maxIntentos): self
    {
        $this->exigir($this->estado === self::COMPENSANDO, 'el vencimiento de la anulación');
        $intentos = $this->intentosCompensacion + 1;
        if ($intentos >= $maxIntentos) {
            return $this->con(self::REQUIERE_REVISION, self::PASO_APLICAR_CARGOS, $ahora, $this->comandoEnCursoId,
                $this->contexto, $this->pasosCon([self::PASO_APLICAR_CARGOS => 'COMPENSACION_FALLIDA']),
                $intentos, "{$this->ultimoError}. Gestión no confirmó la anulación de los cargos tras {$intentos} intentos");
        }
        $espera = $esperaSegundos * (2 ** $intentos);

        return $this->con(self::COMPENSANDO, self::PASO_APLICAR_CARGOS, $ahora->modify("+{$espera} seconds"),
            $comandoAnularId, $this->contexto, $this->pasosActuales(), $intentos, $this->ultimoError);
    }

    /** S3: Gestión confirmó la anulación: la saga queda compensada y la reserva, FALLIDA. */
    public function cargosAnulados(\DateTimeImmutable $ahora): self
    {
        $this->exigir($this->esperaAnulacion(), 'CargoAnulado');

        return $this->con(self::COMPENSADA, self::PASO_APLICAR_CARGOS, $ahora, $this->comandoEnCursoId, $this->contexto, $this->pasosCon([
            self::PASO_BLOQUEAR_HORARIO => 'COMPENSADO',
            self::PASO_APLICAR_CARGOS => 'COMPENSADO',
        ]), $this->intentosCompensacion, $this->ultimoError);
    }

    /** Acceso confirmó la revocación que se envió sin esperar: solo queda anotado en el paso 3. */
    public function pasesRevocados(): ?self
    {
        if ($this->estadoDelPaso(self::PASO_EMITIR_PASES) !== 'FALLIDO') {
            return null;
        }

        return $this->con($this->estado, $this->pasoActual, $this->proximoReintento, $this->comandoEnCursoId,
            $this->contexto, $this->pasosCon([self::PASO_EMITIR_PASES => 'COMPENSADO']),
            $this->intentosCompensacion, $this->ultimoError);
    }

    /**
     * S3: la saga no se puede seguir compensando sola (datos inconsistentes, p. ej. una reserva que ya no está
     * SOLICITADA): queda para el ADMIN y el orquestador no la vuelve a revisar.
     */
    public function aRevision(string $error, \DateTimeImmutable $ahora): self
    {
        return $this->con(self::REQUIERE_REVISION, $this->pasoActual, $ahora, $this->comandoEnCursoId, $this->contexto,
            $this->pasosActuales(), $this->intentosCompensacion, $error);
    }

    public function terminada(): bool
    {
        return in_array($this->estado, [self::COMPLETADA, self::COMPENSADA], true);
    }

    /** @return array<int, string> estado de los 4 pasos */
    public function pasosActuales(): array
    {
        $pasos = [];
        foreach (array_keys(self::NOMBRES) as $numero) {
            $pasos[$numero] = $this->estadoDelPaso($numero);
        }

        return $pasos;
    }

    /**
     * Los pasos actuales con algunos cambiados (array_replace: el spread de PHP renumeraría las claves enteras).
     *
     * @param  array<int, string>  $cambios
     * @return array<int, string>
     */
    private function pasosCon(array $cambios): array
    {
        return array_replace($this->pasosActuales(), $cambios);
    }

    private function exigir(bool $condicion, string $que): void
    {
        if (! $condicion) {
            throw new \DomainException("S2: la saga {$this->id} ({$this->estado}, paso {$this->pasoActual}) no espera {$que}");
        }
    }

    /**
     * @param  array<string, mixed>  $contexto
     * @param  array<int, string>  $pasos
     */
    private function con(
        string $estado,
        int $pasoActual,
        \DateTimeImmutable $proximoReintento,
        string $comandoEnCursoId,
        array $contexto,
        array $pasos,
        ?int $intentosCompensacion = null,
        ?string $ultimoError = null,
    ): self {
        return new self(
            $this->id, $this->reservaId, $estado, $pasoActual, $proximoReintento, $comandoEnCursoId, $contexto, $pasos,
            $intentosCompensacion ?? $this->intentosCompensacion, $ultimoError ?? $this->ultimoError,
        );
    }
}
