<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Messaging;

use CondoHub\Reservas\Application\Saga\ProcesarRespuestaSaga;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * Consumidor de la cola `reservas.saga` (S5): cada respuesta se procesa en su transacción y se confirma (ack) recién
 * después. Un error pasajero (base de datos) se reintenta 3 veces con espera exponencial (S2); un mensaje fuera de
 * contrato o que sigue fallando se rechaza sin reencolar y RabbitMQ lo deja en la cola de fallidos (condohub.dlq).
 * La cola y sus enlaces son de infra/rabbitmq/definitions.json: aquí se declaran pasivos (deben existir).
 */
final class ConsumidorRespuestasSaga
{
    private ?AMQPStreamConnection $conexion = null;

    private ?AMQPChannel $canal = null;

    /** @param list<int> $esperasMs espera antes de cada reintento de un mensaje */
    public function __construct(
        private readonly string $host,
        private readonly int $puerto,
        private readonly string $usuario,
        private readonly string $clave,
        private readonly string $vhost,
        private readonly string $cola,
        private readonly float $esperaSegundos,
        private readonly ProcesarRespuestaSaga $procesar,
        private readonly LoggerInterface $log,
        private readonly array $esperasMs = [500, 1000, 2000],
    ) {}

    /** Procesa los mensajes que lleguen durante `$segundos` como máximo. */
    public function escuchar(int $segundos): void
    {
        try {
            $this->canal()->wait(null, false, max(1, $segundos));
        } catch (AMQPTimeoutException) {
            // Sin mensajes en este intervalo: el comando aprovecha para revisar las esperas vencidas.
        } catch (\Throwable $e) {
            $this->descartarConexion();
            throw $e;
        }
    }

    public function __destruct()
    {
        $this->descartarConexion();
    }

    public function recibir(AMQPMessage $mensaje): void
    {
        try {
            $respuesta = LectorRespuestaSaga::desde($mensaje->getBody());
        } catch (MensajeSagaInvalido $e) {
            $this->log->error('Mensaje de la saga fuera de contrato: va a la cola de fallidos', ['error' => $e->getMessage()]);
            $mensaje->reject(false);

            return;
        }
        $contexto = ['eventId' => $respuesta->eventId, 'tipo' => $respuesta->tipo, 'sagaId' => $respuesta->sagaId, 'reservaId' => $respuesta->reservaId];
        foreach ([0, ...$this->esperasMs] as $intento => $esperaMs) {
            usleep($esperaMs * 1000);
            try {
                $resultado = $this->procesar->ejecutar($respuesta);
                $mensaje->ack();
                $this->log->info("Respuesta de la saga: {$resultado}", $contexto);

                return;
            } catch (\Throwable $e) {
                $this->log->warning('No se pudo procesar una respuesta de la saga', [...$contexto, 'intento' => $intento + 1, 'error' => $e->getMessage()]);
            }
        }
        $this->log->error('Respuesta de la saga sin procesar tras los reintentos: va a la cola de fallidos', $contexto);
        $mensaje->reject(false);
    }

    private function canal(): AMQPChannel
    {
        if ($this->canal !== null && $this->canal->is_open()) {
            return $this->canal;
        }
        $this->conexion = new AMQPStreamConnection(
            $this->host, $this->puerto, $this->usuario, $this->clave, $this->vhost,
            // Lectura más larga que cualquier intervalo de escucha: la espera sin mensajes no es un error de conexión.
            false, 'AMQPLAIN', null, 'en_US', $this->esperaSegundos, max($this->esperaSegundos, 60.0),
        );
        $canal = $this->conexion->channel();
        $canal->queue_declare($this->cola, true);
        $canal->basic_qos(0, 10, false);
        $canal->basic_consume($this->cola, '', false, false, false, false, $this->recibir(...));

        return $this->canal = $canal;
    }

    private function descartarConexion(): void
    {
        try {
            $this->canal?->close();
            $this->conexion?->close();
        } catch (\Throwable) {
            // La conexión ya estaba rota: no hay nada más que cerrar.
        }
        $this->canal = null;
        $this->conexion = null;
    }
}
