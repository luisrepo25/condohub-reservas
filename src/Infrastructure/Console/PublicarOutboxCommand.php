<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Console;

use CondoHub\Reservas\Application\UseCase\PublicarOutbox;
use Illuminate\Console\Command;

/**
 * `php artisan outbox:publicar`: proceso que envía el outbox a RabbitMQ (S4). Corre en bucle (contenedor
 * `reservas-outbox`); con `--una-vez` hace una sola vuelta y termina (pruebas, cron). Un fallo del broker no lo detiene:
 * los mensajes quedan pendientes y se reintentan en la vuelta siguiente.
 */
final class PublicarOutboxCommand extends Command
{
    protected $signature = 'outbox:publicar
        {--una-vez : Hace una sola vuelta y termina}
        {--intervalo= : Segundos entre vueltas (por defecto, OUTBOX_INTERVALO_SEGUNDOS)}
        {--lote= : Mensajes por vuelta (por defecto, OUTBOX_LOTE)}';

    protected $description = 'Publica en RabbitMQ los mensajes pendientes del outbox (S4)';

    public function handle(PublicarOutbox $publicarOutbox): int
    {
        $lote = (int) ($this->option('lote') ?? config('rabbitmq.outbox_lote'));
        $intervalo = max(1, (int) ($this->option('intervalo') ?? config('rabbitmq.outbox_intervalo_segundos')));

        do {
            $resultado = $publicarOutbox->ejecutar($lote);
            if ($resultado->publicados > 0) {
                $this->info("Publicados: {$resultado->publicados}");
            }
            if ($resultado->fallido !== null) {
                $this->warn("Pendiente {$resultado->fallido}: {$resultado->error}");
                if ($this->option('una-vez')) {
                    return self::FAILURE;
                }
            }
            // Si quedaron más del lote, la siguiente vuelta va enseguida.
            $hayMas = $resultado->fallido === null && $resultado->publicados === $lote;
            if (! $this->option('una-vez') && ! $hayMas) {
                sleep($intervalo);
            }
        } while (! $this->option('una-vez'));

        return self::SUCCESS;
    }
}
