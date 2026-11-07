<?php

// Evidencia repetible contra inyección SQL: los ataques clásicos entran por la API real con PostgreSQL real.
// Esperado: nunca un 500, el texto malicioso se guarda tal cual como dato (o se rechaza con 4xx por su formato),
// no cambia el resultado de otras consultas y las tablas siguen intactas.

use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Application\Port\Reloj;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BaseDeDatos;
use Tests\Support\Escenario;
use Tests\Support\FinanzasFalsa;
use Tests\Support\RelojFijo;
use Tests\Support\Tokens;

uses(BaseDeDatos::class);

const INJ_RESIDENTE = '00000000-0000-0000-0000-00000000a002';
const INJ_UNIDAD = '1c672f16-6d63-4534-8c00-3c6813468783';
const INJ_ATAQUES = [
    "' OR '1'='1",
    "'; DROP TABLE reserva; --",
    "x' UNION SELECT usename FROM pg_user --",
    '1; DELETE FROM reserva; --',
    '" OR 1=1 --',
];

beforeEach(function () {
    $this->app->instance(FinanzasGateway::class, new FinanzasFalsa);
    $this->app->instance(Reloj::class, new RelojFijo(Escenario::ahora()));
});

function injArea(): string
{
    return (string) DB::scalar("SELECT id FROM area_comun WHERE codigo = 'SALON'");
}

/** @param array<string, mixed> $cambios */
function injSolicitar(array $cambios = [], ?string $clave = null): TestResponse
{
    $cuerpo = array_merge([
        'areaId' => injArea(),
        'unidadId' => INJ_UNIDAD,
        'inicio' => '2026-10-30T14:00:00-04:00',
        'fin' => '2026-10-30T18:00:00-04:00',
        'invitados' => [['nombre' => 'Ana', 'documento' => '123']],
    ], $cambios);

    return test()->withToken(Tokens::crear(['RESIDENTE'], ['sub' => INJ_RESIDENTE]))
        ->withHeader('Idempotency-Key', $clave ?? (string) Str::uuid())
        ->postJson('/api/reservas', $cuerpo);
}

function injAdmin(): mixed
{
    return test()->withToken(Tokens::crear(['ADMIN'], ['sub' => '00000000-0000-0000-0000-00000000a001']));
}

function injTablasIntactas(): void
{
    foreach (['reserva', 'saga_instancia', 'area_comun'] as $tabla) {
        expect(DB::scalar("SELECT count(*) FROM {$tabla}"))->toBeGreaterThanOrEqual(0);
    }
    expect(DB::scalar('SELECT count(*) FROM area_comun'))->toBe(4);
}

it('los ataques en el nombre y el documento de un invitado se guardan como texto y no se ejecutan', function () {
    // R3 permite 2 reservas activas por unidad: los 5 ataques viajan como invitados de 2 reservas.
    foreach ([array_slice(INJ_ATAQUES, 0, 3), array_slice(INJ_ATAQUES, 3)] as $indice => $grupo) {
        $respuesta = injSolicitar([
            'inicio' => sprintf('2026-11-%02dT14:00:00-04:00', 10 + $indice),
            'fin' => sprintf('2026-11-%02dT18:00:00-04:00', 10 + $indice),
            'invitados' => array_map(
                fn (string $a) => ['nombre' => $a, 'documento' => mb_substr($a, 0, 30)], // el documento admite 30
                $grupo,
            ),
        ])->assertStatus(202);

        $nombres = array_column(DB::select('SELECT nombre FROM invitado_reserva WHERE reserva_id = ?', [$respuesta->json('id')]), 'nombre');
        expect($nombres)->toEqualCanonicalizing($grupo);
    }
    injTablasIntactas();
    expect((int) DB::scalar('SELECT count(*) FROM reserva'))->toBe(2);
});

it('los ataques donde va un identificador se rechazan por su formato y nunca producen un 500', function () {
    foreach (INJ_ATAQUES as $ataque) {
        $estado = injSolicitar(['areaId' => $ataque])->getStatusCode();
        expect($estado)->toBeGreaterThanOrEqual(400)->toBeLessThan(500);

        $estado = injSolicitar(['unidadId' => $ataque])->getStatusCode();
        expect($estado)->toBeGreaterThanOrEqual(400)->toBeLessThan(500);

        $estado = injSolicitar([], $ataque)->getStatusCode(); // en el encabezado Idempotency-Key
        expect($estado)->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
    }
    injTablasIntactas();
    expect((int) DB::scalar('SELECT count(*) FROM reserva'))->toBe(0);
});

it('los ataques en los filtros del listado se rechazan con 400 y no filtran datos', function () {
    injSolicitar()->assertStatus(202);

    foreach (INJ_ATAQUES as $ataque) {
        injAdmin()->getJson('/api/reservas?unidadId='.urlencode($ataque))->assertStatus(400);
        injAdmin()->getJson('/api/reservas?estado='.urlencode($ataque))->assertStatus(400);
    }
    injTablasIntactas();
    expect(injAdmin()->getJson('/api/reservas')->assertOk()->json())->toHaveCount(1);
});

it('los ataques en el id de cancelar y finalizar no producen un 500', function () {
    foreach (INJ_ATAQUES as $ataque) {
        $ruta = rawurlencode($ataque);
        $cancelar = injAdmin()->postJson("/api/reservas/{$ruta}/cancelar", [])->getStatusCode();
        $finalizar = injAdmin()->postJson("/api/reservas/{$ruta}/finalizar", [])->getStatusCode();

        expect($cancelar)->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
        expect($finalizar)->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
    }
    injTablasIntactas();
});
