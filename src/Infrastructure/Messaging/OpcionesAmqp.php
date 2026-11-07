<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Messaging;

/**
 * Opciones comunes de las conexiones a RabbitMQ. Reservas y el broker viven en nubes distintas: sin heartbeat, el
 * firewall descarta en silencio un flujo ocioso y el broker sigue creyéndolo vivo (mensajes «entregados» que nunca
 * llegan). Con heartbeat, cada lado detecta el corte y mantiene el flujo activo.
 */
final class OpcionesAmqp
{
    /** Cada cuántos segundos se intercambian latidos con el broker. */
    public const LATIDO_SEGUNDOS = 30;

    /** php-amqplib exige que el tiempo de lectura sea al menos el doble del heartbeat. */
    public static function lecturaSegundos(float $esperaSegundos): float
    {
        return max($esperaSegundos, 2.0 * self::LATIDO_SEGUNDOS);
    }
}
