<?php

// Integración real del orquestador (saga:consumir) con RabbitMQ (S5). Usa una cola temporal propia de la prueba:
// nunca consume de reservas.saga (allí están las respuestas reales). Solo con el compose levantado:
// RESERVAS_INTEGRACION=1 vendor/bin/pest --group=integracion

use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Application\Port\Reloj;
use CondoHub\Reservas\Infrastructure\Messaging\ConsumidorRespuestasSaga;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\Support\BaseDeDatos;
use Tests\Support\Escenario;
use Tests\Support\FinanzasFalsa;
use Tests\Support\RelojFijo;
use Tests\Support\Tokens;

uses(BaseDeDatos::class);

beforeEach(function () {
    if (env('RESERVAS_INTEGRACION') !== '1') {
        $this->markTestSkipped('Integración con RabbitMQ: activar con RESERVAS_INTEGRACION=1 y el compose levantado');
    }
    $this->app->instance(FinanzasGateway::class, new FinanzasFalsa);
    $this->app->instance(Reloj::class, new RelojFijo(Escenario::ahora()));

    $this->conexion = new AMQPStreamConnection('127.0.0.1', 5672, 'condohub', 'condohub');
    $this->canal = $this->conexion->channel();
    [$this->cola] = $this->canal->queue_declare('reservas.saga.prueba.'.Str::random(8), false, false, false, true);
    config(['rabbitmq.saga_cola' => $this->cola]);
    $this->app->forgetInstance(ConsumidorRespuestasSaga::class);
});

afterEach(function () {
    if (isset($this->canal)) {
        $this->app->forgetInstance(ConsumidorRespuestasSaga::class);
        $this->canal->queue_delete($this->cola);
        $this->canal->close();
        $this->conexion->close();
    }
});

/** @param array<string, mixed> $sobre */
function publicarEnCola(string $cola, array $sobre): void
{
    test()->canal->basic_publish(new AMQPMessage((string) json_encode($sobre), ['delivery_mode' => 2]), '', $cola);
}

it('S5 saga:consumir toma CargoAplicado de la cola, avanza la saga una sola vez y confirma el mensaje', function () {
    $reservaId = test()->withToken(Tokens::crear(['ADMIN']))->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/reservas', [
            'areaId' => (string) DB::scalar("SELECT id FROM area_comun WHERE codigo = 'CHURR-1'"),
            'unidadId' => Escenario::UNIDAD,
            'inicio' => '2026-10-30T14:00:00-04:00',
            'fin' => '2026-10-30T18:00:00-04:00',
        ])->assertStatus(202)->json('id');
    $sagaId = (string) DB::scalar('SELECT id FROM saga_instancia WHERE reserva_id = ?', [$reservaId]);
    $sobre = [
        'eventId' => (string) Str::uuid(), 'type' => 'finanzas.CargoAplicado', 'occurredAt' => '2026-10-26T16:00:00Z',
        'sagaId' => $sagaId, 'version' => 1, 'data' => ['reservaId' => $reservaId, 'unidadId' => Escenario::UNIDAD, 'cargoIds' => []],
    ];
    publicarEnCola($this->cola, $sobre);
    publicarEnCola($this->cola, $sobre);                            // repetido (S5)
    publicarEnCola($this->cola, ['type' => 'otra cosa']);           // fuera de contrato: a la cola de fallidos

    $this->artisan('saga:consumir', ['--una-vez' => true, '--intervalo' => 2])->assertSuccessful();

    expect(DB::scalar('SELECT estado FROM reserva WHERE id = ?', [$reservaId]))->toBe('CONFIRMADA')
        ->and(DB::scalar("SELECT count(*) FROM outbox WHERE routing_key = 'reservas.ReservaConfirmada'"))->toBe(1)
        ->and($this->canal->basic_get($this->cola))->toBeNull();      // los tres quedaron confirmados o rechazados
})->group('integracion');
