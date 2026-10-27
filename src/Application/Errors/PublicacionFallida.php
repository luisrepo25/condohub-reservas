<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Errors;

/** RabbitMQ no recibió o no confirmó un mensaje del outbox. El mensaje queda pendiente y se reintenta después (S4). */
final class PublicacionFallida extends \RuntimeException {}
