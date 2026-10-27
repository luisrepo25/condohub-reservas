<?php

// GET /api/reservas, cancelar (R7) y finalizar (R6) de punta a punta contra PostgreSQL real. Gestión y el reloj son
// dobles. La confirmación de la saga (Fase 3) se simula pasando la reserva a CONFIRMADA en la base.
// "Ahora" = lunes 26/10/2026 12:00 en La Paz; la reserva del salón es el viernes 30/10 de 14:00 a 18:00.

use CondoHub\Reservas\Application\Errors\FinanzasNoDisponible;
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
use Tests\TestCase;

uses(BaseDeDatos::class);

const MI_UNIDAD = '1c672f16-6d63-4534-8c00-3c6813468783';
const OTRA_UNIDAD = '2d783a27-7e74-4645-9d11-4d7924579894';
const SUB_RESIDENTE = '00000000-0000-0000-0000-00000000a002';
const SUB_ADMIN = '00000000-0000-0000-0000-00000000a001';

beforeEach(function () {
    $this->finanzas = new FinanzasFalsa(unidades: [MI_UNIDAD]);
    $this->app->instance(FinanzasGateway::class, $this->finanzas);
    $this->app->instance(Reloj::class, new RelojFijo(Escenario::ahora()));
});

function comoResidente(): TestResponse|TestCase
{
    return test()->withToken(Tokens::crear(['RESIDENTE'], ['sub' => SUB_RESIDENTE]));
}

function comoAdmin(): TestCase
{
    return test()->withToken(Tokens::crear(['ADMIN'], ['sub' => SUB_ADMIN]));
}

/** Crea una reserva por la API (como ADMIN) y, si se pide, la deja CONFIRMADA como lo hará la saga. */
function reservaDe(string $unidad, string $area = 'SALON', bool $confirmada = true, string $dia = '2026-10-30'): string
{
    $id = comoAdmin()->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/reservas', [
        'areaId' => (string) DB::scalar('SELECT id FROM area_comun WHERE codigo = ?', [$area]),
        'unidadId' => $unidad,
        'inicio' => "{$dia}T14:00:00-04:00",
        'fin' => "{$dia}T18:00:00-04:00",
    ])->assertStatus(202)->json('id');
    if ($confirmada) {
        DB::update("UPDATE reserva SET estado = 'CONFIRMADA', version = 1 WHERE id = ?", [$id]);
    }

    return $id;
}

function cancelar(TestCase $como, string $id, int $version = 1, ?string $clave = null, ?string $motivo = null): TestResponse
{
    return $como->withHeader('Idempotency-Key', $clave ?? (string) Str::uuid())
        ->postJson("/api/reservas/{$id}/cancelar", array_filter(['version' => $version, 'motivo' => $motivo], fn ($v) => $v !== null));
}

/** @return list<array<string, string>> data de los AnularCargosReserva de la reserva */
function anulaciones(string $reservaId): array
{
    return array_map(
        fn (object $f) => json_decode($f->payload, true),
        DB::select("SELECT payload FROM outbox WHERE aggregate_id = ? AND routing_key = 'reservas.AnularCargosReserva'", [$reservaId]),
    );
}

// ---------------------------------------------------------------- listar

it('F1 el residente lista solo las reservas de sus unidades; el ADMIN, todas', function () {
    $mia = reservaDe(MI_UNIDAD);
    $ajena = reservaDe(OTRA_UNIDAD, 'CHURR-1');

    expect(array_column(comoResidente()->getJson('/api/reservas')->assertOk()->json(), 'id'))->toBe([$mia]);
    expect(array_column(comoAdmin()->getJson('/api/reservas')->assertOk()->json(), 'id'))->toEqualCanonicalizing([$mia, $ajena]);
});

it('los filtros por unidad y estado funcionan y la respuesta tiene la forma del contrato', function () {
    $confirmada = reservaDe(MI_UNIDAD);
    reservaDe(MI_UNIDAD, 'CHURR-1', confirmada: false);

    $lista = comoResidente()->getJson('/api/reservas?estado=CONFIRMADA&unidadId='.MI_UNIDAD)->assertOk()->json();

    expect(array_column($lista, 'id'))->toBe([$confirmada])
        ->and(array_keys($lista[0]))->toBe([
            'id', 'areaId', 'unidadId', 'inicio', 'fin', 'estado', 'costoUso', 'garantia', 'cantidadInvitados', 'conDanios', 'motivo', 'version',
        ]);
});

it('F1 el residente no puede filtrar por una unidad ajena', function () {
    comoResidente()->getJson('/api/reservas?unidadId='.OTRA_UNIDAD)->assertStatus(403)->assertJson(['code' => 'F1']);
});

it('F1 un residente sin unidades enlazadas ve una lista vacía', function () {
    reservaDe(MI_UNIDAD);
    $this->finanzas->unidades = [];

    comoResidente()->getJson('/api/reservas')->assertOk()->assertExactJson([]);
});

it('filtros inválidos son 400 y sin Gestión es 503', function () {
    comoAdmin()->getJson('/api/reservas?estado=BORRADOR')->assertStatus(400);
    comoAdmin()->getJson('/api/reservas?unidadId=A-101')->assertStatus(400);

    $this->finanzas->error = new FinanzasNoDisponible('caída');
    comoResidente()->getJson('/api/reservas')->assertStatus(503)->assertHeader('Retry-After', '5');
});

// ---------------------------------------------------------------- cancelar

it('R7 cancelar con 48 h o más de anticipación anula todos los cargos en la misma transacción', function () {
    $id = reservaDe(MI_UNIDAD);

    cancelar(comoResidente(), $id, motivo: 'Cambio de planes')->assertOk()
        ->assertJson(['id' => $id, 'estado' => 'CANCELADA', 'motivo' => 'Cambio de planes', 'version' => 2]);

    expect(anulaciones($id))->toEqual([['reservaId' => $id, 'unidadId' => MI_UNIDAD, 'alcance' => 'TODOS']]);
    $sagaDelMensaje = DB::scalar("SELECT saga_id FROM outbox WHERE aggregate_id = ? AND routing_key = 'reservas.AnularCargosReserva'", [$id]);
    expect($sagaDelMensaje)->toBe(DB::scalar('SELECT id FROM saga_instancia WHERE reserva_id = ?', [$id]));
});

it('R7 cancelar con menos de 48 h mantiene el costo de uso y devuelve solo la garantía', function () {
    $id = reservaDe(MI_UNIDAD);
    $this->app->instance(Reloj::class, new RelojFijo(Escenario::local('2026-10-29 15:00')));

    cancelar(comoResidente(), $id)->assertOk()->assertJson(['estado' => 'CANCELADA', 'motivo' => 'Cancelada por el solicitante']);
    expect(anulaciones($id)[0]['alcance'])->toBe('SOLO_GARANTIA');
});

it('R2 cancelar libera el horario para otra reserva', function () {
    $id = reservaDe(MI_UNIDAD);
    cancelar(comoResidente(), $id)->assertOk();

    reservaDe(OTRA_UNIDAD);
    expect(DB::scalar("SELECT count(*) FROM reserva WHERE estado = 'CONFIRMADA'"))->toBe(1);
});

it('O3 repetir la cancelación con la misma clave devuelve lo mismo y no pide anular dos veces', function () {
    $id = reservaDe(MI_UNIDAD);
    $clave = (string) Str::uuid();
    $primera = cancelar(comoResidente(), $id, clave: $clave)->assertOk()->json();

    $repetida = cancelar(comoResidente(), $id, clave: $clave)->assertOk()->json();

    expect($repetida)->toBe($primera)->and(anulaciones($id))->toHaveCount(1);
});

it('O3 la clave de una cancelación no sirve para cancelar otra reserva', function () {
    $una = reservaDe(MI_UNIDAD);
    $otra = reservaDe(MI_UNIDAD, 'CHURR-1');
    $clave = (string) Str::uuid();
    cancelar(comoResidente(), $una, clave: $clave)->assertOk();

    cancelar(comoResidente(), $otra, clave: $clave)->assertStatus(409)->assertJson(['code' => 'O3']);
});

it('O5 una versión vieja no cancela y responde 409', function () {
    $id = reservaDe(MI_UNIDAD);

    cancelar(comoResidente(), $id, version: 0)->assertStatus(409)->assertJson(['code' => 'O5']);
    expect(DB::scalar('SELECT estado FROM reserva WHERE id = ?', [$id]))->toBe('CONFIRMADA')
        ->and(anulaciones($id))->toBe([]);
});

it('R8 solo se cancela una reserva CONFIRMADA', function () {
    $solicitada = reservaDe(MI_UNIDAD, confirmada: false);

    cancelar(comoResidente(), $solicitada, version: 0)->assertStatus(422)->assertJson(['code' => 'R8']);
});

it('F1 un residente no cancela la reserva de otra unidad; el ADMIN sí', function () {
    $ajena = reservaDe(OTRA_UNIDAD);

    cancelar(comoResidente(), $ajena)->assertStatus(403)->assertJson(['code' => 'F1']);
    cancelar(comoAdmin(), $ajena)->assertOk()->assertJson(['estado' => 'CANCELADA']);
});

it('cancelar una reserva inexistente es 404 y sin clave o versión es 400', function () {
    cancelar(comoResidente(), '99999999-9999-4999-8999-999999999999')->assertStatus(404);
    $id = reservaDe(MI_UNIDAD);
    // Los headers de una petición de prueba se conservan en las siguientes: se limpian para probar "sin clave".
    $this->flushHeaders();
    comoResidente()->postJson("/api/reservas/{$id}/cancelar", ['version' => 1])->assertStatus(400);
    comoResidente()->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/reservas/{$id}/cancelar", [])->assertStatus(400);
});

// ---------------------------------------------------------------- finalizar

it('R6 finalizar sin daños devuelve la garantía del salón', function () {
    $id = reservaDe(MI_UNIDAD);

    comoAdmin()->postJson("/api/reservas/{$id}/finalizar", ['conDanios' => false, 'version' => 1])->assertOk()
        ->assertJson(['estado' => 'FINALIZADA', 'conDanios' => false, 'version' => 2]);
    expect(anulaciones($id))->toEqual([['reservaId' => $id, 'unidadId' => MI_UNIDAD, 'alcance' => 'SOLO_GARANTIA']]);
});

it('R6 con daños, o sin garantía, no se pide devolver nada', function () {
    $conDanios = reservaDe(MI_UNIDAD);
    $churrasquera = reservaDe(MI_UNIDAD, 'CHURR-1');

    comoAdmin()->postJson("/api/reservas/{$conDanios}/finalizar", ['conDanios' => true, 'version' => 1])->assertOk()->assertJson(['conDanios' => true]);
    comoAdmin()->postJson("/api/reservas/{$churrasquera}/finalizar", ['conDanios' => false, 'version' => 1])->assertOk();

    expect(anulaciones($conDanios))->toBe([])->and(anulaciones($churrasquera))->toBe([]);
});

it('O5 R8 finalizar exige la versión vigente y una reserva CONFIRMADA', function () {
    $id = reservaDe(MI_UNIDAD);
    $solicitada = reservaDe(MI_UNIDAD, 'CHURR-1', confirmada: false);

    comoAdmin()->postJson("/api/reservas/{$id}/finalizar", ['conDanios' => false, 'version' => 0])->assertStatus(409)->assertJson(['code' => 'O5']);
    comoAdmin()->postJson("/api/reservas/{$solicitada}/finalizar", ['conDanios' => false, 'version' => 0])->assertStatus(422)->assertJson(['code' => 'R8']);
    comoAdmin()->postJson('/api/reservas/99999999-9999-4999-8999-999999999999/finalizar', ['conDanios' => false, 'version' => 1])->assertStatus(404);
});

it('finalizar exige conDanios como true o false', function () {
    $id = reservaDe(MI_UNIDAD);

    comoAdmin()->postJson("/api/reservas/{$id}/finalizar", ['conDanios' => 'no', 'version' => 1])->assertStatus(400);
    comoAdmin()->postJson("/api/reservas/{$id}/finalizar", ['version' => 1])->assertStatus(400);
});

it('S4 el pedido de anulación cumple su contrato de contracts/events', function () {
    $contrato = __DIR__.'/../../../../contracts/events/reservas.AnularCargosReserva.schema.json';
    if (! is_file($contrato)) {
        $this->markTestSkipped('repo abierto sin el espacio de trabajo condohub');
    }
    $esquema = json_decode((string) file_get_contents($contrato), true)['allOf'][1]['properties']['data'];
    $id = reservaDe(MI_UNIDAD);
    cancelar(comoResidente(), $id)->assertOk();

    $data = anulaciones($id)[0];

    expect(array_diff($esquema['required'], array_keys($data)))->toBe([])
        ->and(array_diff(array_keys($data), array_keys($esquema['properties'])))->toBe([])
        ->and($esquema['properties']['alcance']['enum'])->toContain($data['alcance']);
});
