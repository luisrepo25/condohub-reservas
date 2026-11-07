<?php

// S2, S4: las conexiones de Reservas a RabbitMQ (otra nube) no se quedan colgadas tras un rato sin tráfico. Un flujo ocioso
// lo descarta el firewall de la nube sin avisar: el broker cree entregado el mensaje y el cliente nunca lo recibe.

use CondoHub\Reservas\Infrastructure\Messaging\OpcionesAmqp;
use CondoHub\Reservas\Infrastructure\Messaging\ReconexionPorOciosidad;

it('S2 el consumidor usa heartbeat para detectar y mantener viva la conexión', function () {
    expect(OpcionesAmqp::LATIDO_SEGUNDOS)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('S2 el tiempo de lectura es al menos el doble del heartbeat que exige php-amqplib', function (float $espera) {
    expect(OpcionesAmqp::lecturaSegundos($espera))->toBeGreaterThanOrEqual(2 * OpcionesAmqp::LATIDO_SEGUNDOS);
})->with([1.0, 5.0, 30.0, 60.0, 120.0]);

it('S2 una espera de lectura más larga que el mínimo se respeta', function () {
    expect(OpcionesAmqp::lecturaSegundos(120.0))->toBe(120.0);
});

it('S4 el publicador no reutiliza una conexión ociosa más del máximo permitido', function () {
    $politica = new ReconexionPorOciosidad(30.0);

    expect($politica->conviene(100.0, 131.0))->toBeTrue();
});

it('S4 el publicador reutiliza la conexión si se usó hace poco', function () {
    $politica = new ReconexionPorOciosidad(30.0);

    expect($politica->conviene(100.0, 110.0))->toBeFalse();
});

it('S4 justo en el límite todavía se reutiliza la conexión', function () {
    $politica = new ReconexionPorOciosidad(30.0);

    expect($politica->conviene(100.0, 130.0))->toBeFalse();
});

it('S4 sin un uso anterior no hay nada que reconectar', function () {
    $politica = new ReconexionPorOciosidad(30.0);

    expect($politica->conviene(null, 500.0))->toBeFalse();
});

it('S4 el máximo de ociosidad por defecto es menor que lo que tarda el firewall en descartar el flujo', function () {
    $politica = new ReconexionPorOciosidad;

    expect($politica->conviene(0.0, 31.0))->toBeTrue()
        ->and($politica->conviene(0.0, 29.0))->toBeFalse();
});
