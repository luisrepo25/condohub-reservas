<?php

// Integración real del publicador del outbox con RabbitMQ (S4). Usa un exchange y una cola temporales propios de la
// prueba: nunca publica en condohub.events (allí lo consumiría Gestión). Solo con el compose levantado:
// RESERVAS_INTEGRACION=1 vendor/bin/pest --group=integracion

use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Application\Port\PublicadorEventos;
use CondoHub\Reservas\Application\Port\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
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

    $this->exchange = 'condohub.prueba.'.Str::random(8);
    $this->conexion = new AMQPStreamConnection('127.0.0.1', 5672, 'condohub', 'condohub');
    $this->canal = $this->conexion->channel();
    $this->canal->exchange_declare($this->exchange, 'topic', false, false, true);
    config(['rabbitmq.exchange' => $this->exchange]);
    $this->app->forgetInstance(PublicadorEventos::class);
});

afterEach(function () {
    if (isset($this->canal)) {
        $this->canal->exchange_delete($this->exchange);
        $this->canal->close();
        $this->conexion->close();
    }
});

function colaDePrueba(AMQPChannel $canal, string $exchange): string
{
    [$cola] = $canal->queue_declare('', false, false, true, true);
    $canal->queue_bind($cola, $exchange, 'reservas.#');

    return $cola;
}

function solicitarParaPublicar(): string
{
    return test()->withToken(Tokens::crear(['ADMIN']))->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/reservas', [
            'areaId' => (string) DB::scalar("SELECT id FROM area_comun WHERE codigo = 'SALON'"),
            'unidadId' => Escenario::UNIDAD,
            'inicio' => '2026-10-30T14:00:00-04:00',
            'fin' => '2026-10-30T18:00:00-04:00',
        ])->assertStatus(202)->json('id');
}

it('S4 outbox:publicar entrega el comando a RabbitMQ con el sobre del contrato y lo marca publicado', function () {
    $cola = colaDePrueba($this->canal, $this->exchange);
    $reservaId = solicitarParaPublicar();

    $this->artisan('outbox:publicar', ['--una-vez' => true])->assertSuccessful();

    $mensaje = $this->canal->basic_get($cola, true);
    expect($mensaje)->not->toBeNull();
    $sobre = json_decode($mensaje->getBody(), true);
    expect($sobre['type'])->toBe('reservas.AplicarCargosReserva')
        ->and($sobre['version'])->toBe(1)
        ->and($sobre['data']['reservaId'])->toBe($reservaId)
        ->and($sobre['data']['costoUso'])->toBe('500.00')
        ->and($sobre['sagaId'])->toBe(DB::scalar('SELECT id FROM saga_instancia WHERE reserva_id = ?', [$reservaId]))
        ->and($mensaje->get('message_id'))->toBe($sobre['eventId'])
        ->and($mensaje->get('delivery_mode'))->toBe(2)
        ->and(DB::scalar('SELECT count(*) FROM outbox WHERE published_at IS NULL'))->toBe(0);

    // Una segunda vuelta no reenvía nada.
    $this->artisan('outbox:publicar', ['--una-vez' => true])->assertSuccessful();
    expect($this->canal->basic_get($cola, true))->toBeNull();
})->group('integracion');

it('S4 si ninguna cola recibe el mensaje no se pierde: queda pendiente con un intento', function () {
    solicitarParaPublicar();

    $this->artisan('outbox:publicar', ['--una-vez' => true])->assertFailed();

    $fila = DB::selectOne('SELECT published_at, intentos FROM outbox');
    expect($fila->published_at)->toBeNull()->and($fila->intentos)->toBe(1);
})->group('integracion');
