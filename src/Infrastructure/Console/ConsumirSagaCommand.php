<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Console;

use CondoHub\Reservas\Application\Saga\RevisarSagasVencidas;
use CondoHub\Reservas\Infrastructure\Messaging\ConsumidorRespuestasSaga;
use Illuminate\Console\Command;

/**
 * `php artisan saga:consumir`: el orquestador de la saga de reserva (contenedor `reservas-saga`). Escucha las respuestas
 * de Gestión y Acceso (cola `reservas.saga`) y, entre una escucha y otra, compensa las sagas cuyo paso no respondió a
 * tiempo (S2, S3). Al arrancar retoma las que quedaron pendientes (S1). Con `--una-vez` hace una sola vuelta.
 * Si RabbitMQ no responde, lo reintenta en la vuelta siguiente: las esperas vencidas se siguen revisando igual.
 */
final class ConsumirSagaCommand extends Command
{
    protected $signature = 'saga:consumir
        {--una-vez : Hace una sola vuelta y termina}
        {--intervalo= : Segundos entre revisiones de esperas vencidas (por defecto, SAGA_REVISION_SEGUNDOS)}';

    protected $description = 'Orquestador de la saga de reserva: respuestas de los pasos y compensaciones (S1–S5)';

    public function handle(ConsumidorRespuestasSaga $consumidor, RevisarSagasVencidas $revisar): int
    {
        $intervalo = max(1, (int) ($this->option('intervalo') ?? config('rabbitmq.saga_revision_segundos')));
        do {
            try {
                $compensadas = $revisar->ejecutar();
                if ($compensadas > 0) {
                    $this->warn("Sagas con la espera vencida: {$compensadas}");
                }
            } catch (\Throwable $e) {
                // Base de datos caída u otro fallo pasajero: se reintenta en la vuelta siguiente sin detener el proceso.
                report($e);
                $this->error("No se pudieron revisar las esperas vencidas: {$e->getMessage()}");
            }
            try {
                $consumidor->escuchar($intervalo);
            } catch (\Throwable $e) {
                $this->error("RabbitMQ no disponible: {$e->getMessage()}");
                if ($this->option('una-vez')) {
                    return self::FAILURE;
                }
                sleep($intervalo);
            }
        } while (! $this->option('una-vez'));

        return self::SUCCESS;
    }
}
