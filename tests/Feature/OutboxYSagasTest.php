<?php

// Outbox (S4) contra PostgreSQL real y GET /api/sagas/requieren-revision (S3).

use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Application\Port\Reloj;
use CondoHub\Reservas\Infrastructure\Messaging\PgOutboxPendiente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BaseDeDatos;
use Tests\Support\Escenario;
use Tests\Support\FinanzasFalsa;
use Tests\Support\RelojFijo;
use Tests\Support\Tokens;

uses(BaseDeDatos::class);

beforeEach(function () {
    $this->app->instance(FinanzasGateway::class, new FinanzasFalsa);
    $this->app->instance(Reloj::class, new RelojFijo(Escenario::ahora()));
});

/** Una reserva solicitada por la API: deja su AplicarCargosReserva en el outbox. Devuelve el id de la reserva. */
function reservaConComando(string $area = 'SALON'): string
{
    return test()->withToken(Tokens::crear(['ADMIN']))->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/reservas', [
            'areaId' => (string) DB::scalar('SELECT id FROM area_comun WHERE codigo = ?', [$area]),
            'unidadId' => Escenario::UNIDAD,
            'inicio' => '2026-10-30T14:00:00-04:00',
            'fin' => '2026-10-30T18:00:00-04:00',
        ])->assertStatus(202)->json('id');
}

it('S4 el outbox entrega los pendientes en orden con lo necesario para el sobre', function () {
    $primera = reservaConComando('SALON');
    $segunda = reservaConComando('CHURR-1');

    $pendientes = app(PgOutboxPendiente::class)->tomarPendientes(10);

    expect($pendientes)->toHaveCount(2)
        ->and(json_decode($pendientes[0]->dataJson, true)['reservaId'])->toBe($primera)
        ->and(json_decode($pendientes[1]->dataJson, true)['reservaId'])->toBe($segunda)
        ->and($pendientes[0]->tipo)->toBe('reservas.AplicarCargosReserva')
        ->and($pendientes[0]->sagaId)->toBe(DB::scalar('SELECT id FROM saga_instancia WHERE reserva_id = ?', [$primera]));
});

it('S4 un mensaje publicado no vuelve a salir y un fallo suma un intento sin publicarlo', function () {
    reservaConComando('SALON');
    reservaConComando('CHURR-1');
    $outbox = app(PgOutboxPendiente::class);
    [$publicado, $fallido] = $outbox->tomarPendientes(10);

    $outbox->marcarPublicado($publicado->id);
    $outbox->registrarIntentoFallido($fallido->id);

    expect(array_map(fn ($m) => $m->id, $outbox->tomarPendientes(10)))->toBe([$fallido->id]);
    $filas = collect(DB::select('SELECT id, intentos, published_at FROM outbox'))->keyBy('id');
    expect($filas[$publicado->id]->published_at)->not->toBeNull()
        ->and($filas[$fallido->id]->published_at)->toBeNull()
        ->and($filas[$fallido->id]->intentos)->toBe(1);
});

it('S3 el ADMIN ve las sagas que requieren revisión, y solo esas', function () {
    $atascada = reservaConComando('SALON');
    reservaConComando('CHURR-1');
    DB::update(
        "UPDATE saga_instancia SET estado = 'REQUIERE_REVISION', ultimo_error = 'Gestión no anuló los cargos' WHERE reserva_id = ?",
        [$atascada],
    );

    $sagas = test()->withToken(Tokens::crear(['ADMIN']))->getJson('/api/sagas/requieren-revision')->assertOk()->json();

    expect($sagas)->toHaveCount(1)
        ->and($sagas[0])->toMatchArray([
            'reservaId' => $atascada, 'estado' => 'REQUIERE_REVISION', 'pasoActual' => 2, 'ultimoError' => 'Gestión no anuló los cargos',
        ])
        ->and(array_keys($sagas[0]))->toBe(['id', 'reservaId', 'estado', 'pasoActual', 'ultimoError']);
});

it('S3 sin sagas atascadas la lista está vacía', function () {
    reservaConComando();

    test()->withToken(Tokens::crear(['ADMIN']))->getJson('/api/sagas/requieren-revision')->assertOk()->assertExactJson([]);
});
