<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\ValueObject;

/**
 * Parámetros de negocio de las reservas (tabla `parametro`): nunca constantes en el código. La zona horaria es la del
 * condominio: el horario reservable (R2) se mide en hora local, aunque las reservas se guardan en UTC.
 */
final readonly class ParametrosReserva
{
    public function __construct(
        /** R3 */
        public int $anticipacionMinHoras,
        /** R3 */
        public int $anticipacionMaxDias,
        /** R3 */
        public int $maxActivasPorUnidad,
        /** R7 */
        public int $cancelacionSinCostoHoras,
        /** R4: con esta cantidad o más de expensas vencidas la unidad no reserva */
        public int $expensasVencidasBloqueo,
        /** R2 */
        public \DateTimeZone $zonaHoraria,
    ) {
        $valores = [$anticipacionMinHoras, $anticipacionMaxDias, $maxActivasPorUnidad, $cancelacionSinCostoHoras];
        foreach ($valores as $valor) {
            if ($valor < 0) {
                throw new \InvalidArgumentException('Los parámetros de reservas no pueden ser negativos');
            }
        }
        if ($expensasVencidasBloqueo < 1) {
            throw new \InvalidArgumentException('R4: el bloqueo por morosidad necesita al menos 1 expensa vencida');
        }
    }
}
