<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Messaging;

use CondoHub\Reservas\Application\Errors\PublicacionFallida;
use CondoHub\Reservas\Application\Outbox\MensajePendiente;
use CondoHub\Reservas\Application\Port\PublicadorEventos;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * PublicadorEventos sobre RabbitMQ (php-amqplib). Cada mensaje se publica persistente, con `mandatory` (si ninguna cola
 * lo recibe, cuenta como fallo y no se pierde) y con confirmación del broker (publisher confirms). La conexión se abre
 * al primer envío, se reutiliza entre vueltas y se descarta ante cualquier error para reconectar en la siguiente.
 * La topología (exchange, colas) es de infra/rabbitmq/definitions.json: aquí solo se comprueba que el exchange exista.
 */
final class PublicadorRabbitMq implements PublicadorEventos
{
    private ?AMQPStreamConnection $conexion = null;

    private ?AMQPChannel $canal = null;

    private ?string $rechazo = null;

    public function __construct(
        private readonly string $host,
        private readonly int $puerto,
        private readonly string $usuario,
        private readonly string $clave,
        private readonly string $vhost,
        private readonly string $exchange,
        private readonly float $esperaSegundos,
        private readonly LoggerInterface $log,
    ) {}

    public function publicar(MensajePendiente $mensaje): void
    {
        try {
            $canal = $this->canal();
            $this->rechazo = null;
            $canal->basic_publish(new AMQPMessage(SobreEvento::json($mensaje), [
                'content_type' => 'application/json',
                'content_encoding' => 'utf-8',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $mensaje->id,
                'type' => $mensaje->tipo,
            ]), $this->exchange, $mensaje->tipo, true);
            $canal->wait_for_pending_acks_returns($this->esperaSegundos);
        } catch (\Throwable $e) {
            $this->descartarConexion();
            $this->log->warning('RabbitMQ no confirmó un mensaje del outbox', ['eventId' => $mensaje->id, 'tipo' => $mensaje->tipo, 'error' => $e->getMessage()]);
            throw new PublicacionFallida("RabbitMQ no confirmó el mensaje {$mensaje->id}: {$e->getMessage()}", previous: $e);
        }

        $rechazo = $this->rechazoDelBroker();
        if ($rechazo !== null) {
            $this->log->warning('RabbitMQ rechazó un mensaje del outbox', ['eventId' => $mensaje->id, 'tipo' => $mensaje->tipo, 'motivo' => $rechazo]);
            throw new PublicacionFallida("RabbitMQ rechazó el mensaje {$mensaje->id}: {$rechazo}");
        }
    }

    /** Lo que avisaron los listeners de nack o de mensaje sin cola durante la espera de confirmación. */
    private function rechazoDelBroker(): ?string
    {
        return $this->rechazo;
    }

    public function __destruct()
    {
        $this->descartarConexion();
    }

    private function canal(): AMQPChannel
    {
        if ($this->canal !== null && $this->canal->is_open()) {
            return $this->canal;
        }
        $this->conexion = new AMQPStreamConnection(
            $this->host, $this->puerto, $this->usuario, $this->clave, $this->vhost,
            false, 'AMQPLAIN', null, 'en_US', $this->esperaSegundos, $this->esperaSegundos,
        );
        $canal = $this->conexion->channel();
        // passive: el exchange debe existir (lo crea la infraestructura); si no está, es un error de configuración.
        $canal->exchange_declare($this->exchange, 'topic', true);
        $canal->confirm_select();
        $canal->set_nack_handler(function (): void {
            $this->rechazo = 'el broker respondió nack';
        });
        $canal->set_return_listener(function (int $codigo, string $texto): void {
            $this->rechazo = "ninguna cola lo recibe ({$codigo} {$texto})";
        });

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
