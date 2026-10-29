<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Persistence;

use CondoHub\Reservas\Domain\Port\ParametroRepository;
use CondoHub\Reservas\Domain\ValueObject\ParametrosReserva;
use Illuminate\Database\ConnectionInterface;

/**
 * Parámetros de la tabla `parametro`. Un parámetro que falta o no es un entero es un error de configuración: se avisa
 * con su clave en vez de usar un valor inventado. La zona horaria del condominio viene de la configuración.
 */
final class PgParametroRepository implements ParametroRepository
{
    public function __construct(private readonly ConnectionInterface $db, private readonly string $zonaHoraria) {}

    public function reserva(): ParametrosReserva
    {
        $valores = $this->leer();

        return new ParametrosReserva(
            self::entero($valores, 'reserva.anticipacion_min_horas'),
            self::entero($valores, 'reserva.anticipacion_max_dias'),
            self::entero($valores, 'reserva.max_activas_por_unidad'),
            self::entero($valores, 'reserva.cancelacion_sin_costo_horas'),
            self::entero($valores, 'reserva.expensas_vencidas_bloqueo'),
            new \DateTimeZone($this->zonaHoraria),
        );
    }

    public function esperaPasoSagaSegundos(): int
    {
        return self::entero($this->leer(), 'saga.timeout_paso_segundos');
    }

    public function reintentosPorPasoSaga(): int
    {
        return max(1, self::entero($this->leer(), 'saga.reintentos_por_paso'));
    }

    /** @return array<string, string> */
    private function leer(): array
    {
        $valores = [];
        foreach ($this->db->select('SELECT clave, valor FROM parametro') as $fila) {
            /** @var object{clave: string, valor: string} $fila */
            $valores[$fila->clave] = $fila->valor;
        }

        return $valores;
    }

    /** @param array<string, string> $valores */
    private static function entero(array $valores, string $clave): int
    {
        $valor = $valores[$clave] ?? throw new \RuntimeException("Falta el parámetro '{$clave}' en la tabla parametro");
        if (preg_match('/^\d+$/', trim($valor)) !== 1) {
            throw new \RuntimeException("El parámetro '{$clave}' debe ser un entero no negativo y vale '{$valor}'");
        }

        return (int) $valor;
    }
}
