<?php

// POST /api/reservas de punta a punta contra PostgreSQL real: ruta, JWT, validación, caso de uso, reglas del dominio,
// reserva + saga (S1) + outbox (S4) en una transacción. Gestión y el reloj son dobles (la integración real con Gestión
// está en IntegracionGestionTest). "Ahora" = lunes 26/10/2026 12:00 en La Paz.

use CondoHub\Reservas\Application\Errors\FinanzasNoDisponible;
use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Application\Port\Reloj;
use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BaseDeDatos;
use Tests\Support\Escenario;
use Tests\Support\FinanzasFalsa;
use Tests\Support\RelojFijo;
use Tests\Support\Tokens;

uses(BaseDeDatos::class);

const RESIDENTE_SUB = '00000000-0000-0000-0000-00000000a002';
const UNIDAD_A101 = '1c672f16-6d63-4534-8c00-3c6813468783';

beforeEach(function () {
    $this->finanzas = new FinanzasFalsa;
    $this->app->instance(FinanzasGateway::class, $this->finanzas);
    $this->app->instance(Reloj::class, new RelojFijo(Escenario::ahora()));
});

function areaId(string $codigo): string
{
    return (string) DB::scalar('SELECT id FROM area_comun WHERE codigo = ?', [$codigo]);
}

/** @param array<string, mixed> $cambios */
function pedido(array $cambios = []): array
{
    return array_merge([
        'areaId' => areaId('SALON'),
        'unidadId' => UNIDAD_A101,
        'inicio' => '2026-10-30T14:00:00-04:00',
        'fin' => '2026-10-30T18:00:00-04:00',
        'invitados' => [['nombre' => 'Ana Pérez', 'documento' => '123'], ['nombre' => 'Luis Rojas']],
    ], $cambios);
}

function solicitar(array $cuerpo, ?string $clave = null, string $sub = RESIDENTE_SUB, string $rol = 'RESIDENTE'): TestResponse
{
    return test()->withToken(Tokens::crear([$rol], ['sub' => $sub]))
        ->withHeader('Idempotency-Key', $clave ?? (string) Str::uuid())
        ->postJson('/api/reservas', $cuerpo);
}

function filas(string $tabla): int
{
    return (int) DB::scalar("SELECT count(*) FROM {$tabla}");
}

it('R5 S1 S4 una reserva válida queda SOLICITADA con su saga y su comando en el outbox, en una sola respuesta 202', function () {
    $respuesta = solicitar(pedido())->assertStatus(202);

    expect($respuesta->json())->toMatchArray([
        'areaId' => areaId('SALON'),
        'unidadId' => UNIDAD_A101,
        'inicio' => '2026-10-30T18:00:00Z',
        'fin' => '2026-10-30T22:00:00Z',
        'estado' => 'SOLICITADA',
        'costoUso' => '500.00',
        'garantia' => '300.00',
        'cantidadInvitados' => 2,
        'conDanios' => null,
        'motivo' => null,
        'version' => 0,
    ]);
    $reservaId = $respuesta->json('id');
    expect($this->finanzas->consultadas)->toBe([UNIDAD_A101]);

    $reserva = DB::selectOne('SELECT solicitada_por, estado FROM reserva WHERE id = ?', [$reservaId]);
    expect($reserva->solicitada_por)->toBe(RESIDENTE_SUB)->and($reserva->estado)->toBe('SOLICITADA');
    expect(DB::scalar('SELECT count(*) FROM invitado_reserva WHERE reserva_id = ?', [$reservaId]))->toBe(2);

    $saga = DB::selectOne('SELECT id, estado, paso_actual FROM saga_instancia WHERE reserva_id = ?', [$reservaId]);
    expect($saga->estado)->toBe('EN_CURSO')->and($saga->paso_actual)->toBe(2);
    $pasos = DB::select('SELECT numero, nombre, estado, comando_event_id FROM saga_paso WHERE saga_id = ? ORDER BY numero', [$saga->id]);
    expect(array_column($pasos, 'estado'))->toBe(['OK', 'EN_CURSO', 'PENDIENTE', 'PENDIENTE'])
        ->and(array_column($pasos, 'nombre'))->toBe(['BLOQUEAR_HORARIO', 'APLICAR_CARGOS', 'EMITIR_PASES', 'NOTIFICAR']);

    $mensaje = DB::selectOne('SELECT id, routing_key, saga_id, payload, published_at FROM outbox WHERE aggregate_id = ?', [$reservaId]);
    expect($mensaje->routing_key)->toBe('reservas.AplicarCargosReserva')
        ->and($mensaje->saga_id)->toBe($saga->id)
        ->and($mensaje->id)->toBe($pasos[1]->comando_event_id)
        ->and($mensaje->published_at)->toBeNull()
        // JSONB no conserva el orden de las claves: se compara el contenido.
        ->and(json_decode($mensaje->payload, true))->toEqual([
            'reservaId' => $reservaId, 'unidadId' => UNIDAD_A101, 'areaComun' => 'SALON', 'costoUso' => '500.00', 'garantia' => '300.00',
        ]);
});

it('S4 el comando del outbox cumple su contrato de contracts/events', function () {
    $contrato = __DIR__.'/../../../../contracts/events/reservas.AplicarCargosReserva.schema.json';
    if (! is_file($contrato)) {
        $this->markTestSkipped('repo abierto sin el espacio de trabajo condohub');
    }
    $esquema = json_decode((string) file_get_contents($contrato), true)['allOf'][1]['properties']['data'];
    solicitar(pedido())->assertStatus(202);

    $data = json_decode((string) DB::scalar('SELECT payload FROM outbox'), true);

    expect(array_diff($esquema['required'], array_keys($data)))->toBe([])
        ->and(array_diff(array_keys($data), array_keys($esquema['properties'])))->toBe([]);
    foreach (['costoUso', 'garantia'] as $monto) {
        expect($data[$monto])->toMatch('/'.trim($esquema['properties'][$monto]['pattern'], '^$').'$/');
    }
});

it('O3 repetir la misma Idempotency-Key devuelve la misma reserva sin crear otra ni volver a consultar', function () {
    $clave = (string) Str::uuid();
    $primera = solicitar(pedido(), $clave)->assertStatus(202)->json();

    $repetida = solicitar(pedido(), $clave)->assertStatus(202)->json();

    expect($repetida)->toBe($primera)
        ->and(filas('reserva'))->toBe(1)
        ->and(filas('saga_instancia'))->toBe(1)
        ->and(filas('outbox'))->toBe(1)
        ->and($this->finanzas->consultadas)->toHaveCount(1);
});

it('O3 la clave de otra persona no devuelve su reserva', function () {
    $clave = (string) Str::uuid();
    solicitar(pedido(), $clave)->assertStatus(202);

    solicitar(pedido(), $clave, '00000000-0000-0000-0000-00000000ffff')
        ->assertStatus(409)->assertJson(['code' => 'O3']);
});

it('O1 una Idempotency-Key que no es UUID v4 se rechaza', function () {
    solicitar(pedido(), 'no-es-uuid')->assertStatus(422)->assertJson(['code' => 'O1']);
});

it('R4 una unidad con 2 o más expensas vencidas no reserva y no queda nada guardado', function () {
    $this->finanzas->vencidas = 2;

    solicitar(pedido())->assertStatus(422)->assertJson(['code' => 'R4']);
    expect(filas('reserva') + filas('saga_instancia') + filas('outbox'))->toBe(0);
});

it('F1 un residente no reserva para una unidad ajena', function () {
    $this->finanzas->error = new ReglaNegocioViolada('F1', 'Solo puedes reservar para una unidad tuya');

    solicitar(pedido())->assertStatus(403)->assertJson(['code' => 'F1']);
    expect(filas('reserva'))->toBe(0);
});

it('R4 sin respuesta de Gestión no se reserva: 503 con Retry-After', function () {
    $this->finanzas->error = new FinanzasNoDisponible('caída');

    solicitar(pedido())->assertStatus(503)->assertHeader('Retry-After', '5')
        ->assertHeader('Content-Type', 'application/problem+json');
    expect(filas('reserva') + filas('outbox'))->toBe(0);
});

it('R2 un horario ocupado del mismo área se rechaza; otra área en el mismo horario sí se puede', function () {
    solicitar(pedido())->assertStatus(202);

    solicitar(pedido(['inicio' => '2026-10-30T17:00:00-04:00', 'fin' => '2026-10-30T20:00:00-04:00']))
        ->assertStatus(422)->assertJson(['code' => 'R2']);
    solicitar(pedido(['areaId' => areaId('CHURR-1'), 'invitados' => []]))->assertStatus(202);
});

it('R2 R3 las reglas del dominio llegan al cliente con su código', function (array $cambios, string $codigo) {
    solicitar(pedido($cambios))->assertStatus(422)->assertJson(['code' => $codigo]);
    expect(filas('reserva'))->toBe(0);
})->with([
    'fuera de horario (R2)' => [['inicio' => '2026-10-30T06:00:00-04:00', 'fin' => '2026-10-30T09:00:00-04:00'], 'R2'],
    'bloque menor a 2 h (R2)' => [['fin' => '2026-10-30T15:00:00-04:00'], 'R2'],
    'menos de 48 h (R3)' => [['inicio' => '2026-10-27T14:00:00-04:00', 'fin' => '2026-10-27T18:00:00-04:00'], 'R3'],
    'más de 60 días (R3)' => [['inicio' => '2027-01-05T14:00:00-04:00', 'fin' => '2027-01-05T18:00:00-04:00'], 'R3'],
]);

it('R3 una unidad no tiene más de 2 reservas activas', function () {
    solicitar(pedido())->assertStatus(202);
    solicitar(pedido(['areaId' => areaId('CHURR-1'), 'invitados' => []]))->assertStatus(202);

    solicitar(pedido(['areaId' => areaId('PISCINA'), 'invitados' => []]))->assertStatus(422)->assertJson(['code' => 'R3']);
});

it('R1 el aforo cuenta a quien reserva y un área inexistente o inactiva se rechaza', function () {
    $invitados = array_map(fn (int $i) => ['nombre' => "Invitado {$i}"], range(1, 15));
    solicitar(pedido(['areaId' => areaId('CHURR-1'), 'invitados' => $invitados]))->assertStatus(422)->assertJson(['code' => 'R1']);

    solicitar(pedido(['areaId' => '99999999-9999-4999-8999-999999999999']))->assertStatus(422)->assertJson(['code' => 'R1']);

    DB::update("UPDATE area_comun SET activa = false WHERE codigo = 'PISCINA'");
    solicitar(pedido(['areaId' => areaId('PISCINA'), 'invitados' => []]))->assertStatus(422)->assertJson(['code' => 'R1']);
});

it('una solicitud mal formada es 400 problem+json y no consulta a Gestión', function (array $cuerpo, ?string $clave) {
    $peticion = test()->withToken(Tokens::crear(['RESIDENTE'], ['sub' => RESIDENTE_SUB]));
    if ($clave !== null) {
        $peticion = $peticion->withHeader('Idempotency-Key', $clave);
    }

    $peticion->postJson('/api/reservas', $cuerpo)->assertStatus(400)->assertHeader('Content-Type', 'application/problem+json');
    expect($this->finanzas->consultadas)->toBe([]);
})->with([
    'sin Idempotency-Key' => [fn () => pedido(), null],
    'área que no es uuid' => [fn () => pedido(['areaId' => 'salon']), '7c1f2d9e-1b6a-4c3e-9a51-2f0d8e7b6a43'],
    'fecha sin zona' => [fn () => pedido(['inicio' => '2026-10-30 14:00']), '7c1f2d9e-1b6a-4c3e-9a51-2f0d8e7b6a43'],
    'invitado sin nombre' => [fn () => pedido(['invitados' => [['documento' => '1']]]), '7c1f2d9e-1b6a-4c3e-9a51-2f0d8e7b6a43'],
]);

it('dos invitados con el mismo documento son una solicitud inválida', function () {
    $invitados = [['nombre' => 'A', 'documento' => '1'], ['nombre' => 'B', 'documento' => '1']];

    solicitar(pedido(['invitados' => $invitados]))->assertStatus(400)->assertJsonPath('detail', 'Hay invitados con el mismo documento');
    expect(filas('reserva'))->toBe(0);
});

it('el ADMIN puede reservar para cualquier unidad (F1 lo decide Gestión)', function () {
    solicitar(pedido(), null, '00000000-0000-0000-0000-00000000a001', 'ADMIN')->assertStatus(202)
        ->assertJson(['unidadId' => UNIDAD_A101]);
    expect(DB::scalar('SELECT solicitada_por FROM reserva'))->toBe('00000000-0000-0000-0000-00000000a001');
});
