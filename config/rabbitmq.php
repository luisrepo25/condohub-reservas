<?php

// RabbitMQ (S4): a dónde publica el outbox de Reservas. Topología en infra/rabbitmq/definitions.json.
// En Docker: host `rabbitmq`. Credenciales de desarrollo por defecto; en otros entornos vienen del entorno.

return [
    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
    'puerto' => (int) env('RABBITMQ_PORT', 5672),
    'usuario' => env('RABBITMQ_USER', 'condohub'),
    'clave' => env('RABBITMQ_PASSWORD', 'condohub'),
    'vhost' => env('RABBITMQ_VHOST', '/'),
    'exchange' => env('RABBITMQ_EXCHANGE', 'condohub.events'),
    // Espera máxima de conexión y de la confirmación del broker por mensaje.
    'espera_segundos' => (float) env('RABBITMQ_ESPERA_SEGUNDOS', 5),
    // Publicador del outbox: cada cuántos segundos revisa y cuántos mensajes toma por vuelta.
    'outbox_intervalo_segundos' => (int) env('OUTBOX_INTERVALO_SEGUNDOS', 2),
    'outbox_lote' => (int) env('OUTBOX_LOTE', 50),
];
