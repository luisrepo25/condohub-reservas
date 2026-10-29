<?php

// Orquestador de la saga de reserva (S1–S5) contra PostgreSQL real: respuestas de Gestión y Acceso, plazos vencidos y
// compensaciones. Gestión y el reloj son dobles; los mensajes se leen de la tabla outbox (S4).
// "Ahora" = lunes 26/10/2026 12:00 en La Paz; la reserva del salón es el viernes 30/10 de 14:00 a 18:00.

use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Application\Port\Reloj;
use CondoHub\Reservas\Application\Saga\ProcesarRespuestaSaga;
use CondoHub\Reservas\Application\Saga\RespuestaSaga;
use CondoHub\Reservas\Application\Saga\RevisarSagasVencidas;
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

/**
 * Solicita una reserva por la API (paso 1) y devuelve [reservaId, sagaId].
 *
 * @param  list<array{nombre: string, documento?: string}>  $invitados
 * @return array{0: string, 1: string}
 */
function reservaEnSaga(array $invitados = [['nombre' => 'Ana Pérez', 'documento' => '123'], ['nombre' => 'Luis Rojas']]): array
{
    $id = test()->withToken(Tokens::crear(['ADMIN']))->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/reservas', [
            'areaId' => (string) DB::scalar("SELECT id FROM area_comun WHERE codigo = 'SALON'"),
            'unidadId' => Escenario::UNIDAD,
            'inicio' => '2026-10-30T14:00:00-04:00',
            'fin' => '2026-10-30T18:00:00-04:00',
            'invitados' => $invitados,
        ])->assertStatus(202)->json('id');

    return [$id, (string) DB::scalar('SELECT id FROM saga_instancia WHERE reserva_id = ?', [$id])];
}

/** @param list<string> $ids */
function responder(string $tipo, string $reservaId, ?string $sagaId, array $ids = [], ?string $eventId = null): string
{
    return app(ProcesarRespuestaSaga::class)->ejecutar(
        new RespuestaSaga($eventId ?? (string) Str::uuid(), $tipo, $sagaId, $reservaId, $ids),
    );
}

/** El orquestador revisa los plazos `$segundos` después de "ahora". */
function revisarA(int $segundos): int
{
    app()->instance(Reloj::class, new RelojFijo(Escenario::ahora()->modify("+{$segundos} seconds")));

    return app(RevisarSagasVencidas::class)->ejecutar();
}

/** @return list<array{tipo: string, data: array<string, mixed>}> mensajes de la reserva en el outbox, en orden */
function mensajesDe(string $reservaId): array
{
    return array_map(static fn (object $f): array => ['tipo' => $f->routing_key, 'data' => json_decode($f->payload, true)], DB::select(
        'SELECT routing_key, payload::text AS payload FROM outbox WHERE aggregate_id = ? ORDER BY occurred_at, routing_key',
        [$reservaId],
    ));
}

/** @return list<string> */
function tiposDe(string $reservaId): array
{
    return array_column(mensajesDe($reservaId), 'tipo');
}

/** @return array<string, mixed> */
function ultimo(string $reservaId, string $tipo): array
{
    $mensajes = array_values(array_filter(mensajesDe($reservaId), static fn (array $m): bool => $m['tipo'] === $tipo));

    return end($mensajes)['data'] ?? [];
}

function estadoReserva(string $id): string
{
    return (string) DB::scalar('SELECT estado FROM reserva WHERE id = ?', [$id]);
}

function estadoSaga(string $sagaId): string
{
    return (string) DB::scalar('SELECT estado FROM saga_instancia WHERE id = ?', [$sagaId]);
}

/** @return list<string> */
function pasosDe(string $sagaId): array
{
    return array_map(static fn (object $f): string => $f->estado, DB::select('SELECT estado FROM saga_paso WHERE saga_id = ? ORDER BY numero', [$sagaId]));
}

it('S1 la saga guarda el código de la unidad que dio Gestión al reservar', function () {
    [, $sagaId] = reservaEnSaga();

    expect(json_decode((string) DB::scalar('SELECT contexto::text FROM saga_instancia WHERE id = ?', [$sagaId]), true))
        ->toBe(['unidadCodigo' => 'A-101']);
});

it('S2 con los cargos aplicados se piden los pases de los invitados a Acceso (paso 3)', function () {
    [$reservaId, $sagaId] = reservaEnSaga();

    expect(responder(RespuestaSaga::CARGO_APLICADO, $reservaId, $sagaId, ['c1', 'c2']))->toBe(ProcesarRespuestaSaga::AVANZO);

    $pedido = ultimo($reservaId, 'reservas.EmitirPasesReserva');
    expect($pedido)->toMatchArray(['reservaId' => $reservaId, 'unidadId' => Escenario::UNIDAD, 'unidadCodigo' => 'A-101', 'fecha' => '2026-10-30'])
        ->and(array_column($pedido['invitados'], 'nombre'))->toBe(['Ana Pérez', 'Luis Rojas'])
        ->and(array_column($pedido['invitados'], 'invitadoId'))
        ->toBe(array_map(static fn (object $f): string => $f->id, DB::select('SELECT id FROM invitado_reserva WHERE reserva_id = ? ORDER BY nombre', [$reservaId])))
        ->and(estadoReserva($reservaId))->toBe('SOLICITADA')
        ->and(pasosDe($sagaId))->toBe(['OK', 'OK', 'EN_CURSO', 'PENDIENTE'])
        ->and(DB::scalar('SELECT comando_event_id FROM saga_paso WHERE saga_id = ? AND numero = 3', [$sagaId]))
        ->toBe(DB::scalar("SELECT id FROM outbox WHERE routing_key = 'reservas.EmitirPasesReserva'"));
});

it('S2 con los pases emitidos la reserva queda CONFIRMADA y se avisa al residente (paso 4)', function () {
    [$reservaId, $sagaId] = reservaEnSaga();
    responder(RespuestaSaga::CARGO_APLICADO, $reservaId, $sagaId, ['c1']);

    responder(RespuestaSaga::PASE_EMITIDO, $reservaId, $sagaId, ['QR1', 'QR2']);

    expect(estadoReserva($reservaId))->toBe('CONFIRMADA')
        ->and(estadoSaga($sagaId))->toBe('COMPLETADA')
        ->and(DB::scalar('SELECT terminada_en IS NOT NULL FROM saga_instancia WHERE id = ?', [$sagaId]))->toBeTrue()
        ->and(pasosDe($sagaId))->toBe(['OK', 'OK', 'OK', 'OK'])
        // JSONB no conserva el orden de las claves: se compara el contenido.
        ->and(ultimo($reservaId, 'reservas.ReservaConfirmada'))->toEqual([
            'reservaId' => $reservaId, 'unidadId' => Escenario::UNIDAD, 'areaComun' => 'Salón de eventos',
            'inicio' => '2026-10-30T18:00:00Z', 'fin' => '2026-10-30T22:00:00Z',
        ]);
});

it('S2 sin invitados la reserva se confirma con los cargos aplicados (el paso 3 no aplica)', function () {
    [$reservaId, $sagaId] = reservaEnSaga([]);

    responder(RespuestaSaga::CARGO_APLICADO, $reservaId, $sagaId, []);

    expect(estadoReserva($reservaId))->toBe('CONFIRMADA')
        ->and(pasosDe($sagaId))->toBe(['OK', 'OK', 'OMITIDO', 'OK'])
        ->and(tiposDe($reservaId))->toBe(['reservas.AplicarCargosReserva', 'reservas.ReservaConfirmada']);
});

it('S5 una respuesta repetida se descarta sin volver a pedir los pases', function () {
    [$reservaId, $sagaId] = reservaEnSaga();
    $eventId = (string) Str::uuid();

    responder(RespuestaSaga::CARGO_APLICADO, $reservaId, $sagaId, ['c1'], $eventId);

    expect(responder(RespuestaSaga::CARGO_APLICADO, $reservaId, $sagaId, ['c1'], $eventId))->toBe(ProcesarRespuestaSaga::REPETIDA)
        ->and(array_count_values(tiposDe($reservaId))['reservas.EmitirPasesReserva'])->toBe(1);
});

it('S1 una respuesta sin sagaId se correlaciona por la reserva', function () {
    [$reservaId, $sagaId] = reservaEnSaga([]);

    responder(RespuestaSaga::CARGO_APLICADO, $reservaId, null);

    expect(estadoSaga($sagaId))->toBe('COMPLETADA');
});

it('S2 antes de su plazo ninguna saga se compensa', function () {
    reservaEnSaga();

    expect(revisarA(29))->toBe(0);
});

it('S2 si Gestión no aplica los cargos a tiempo, la reserva falla ya y la anulación se envía sin esperarla', function () {
    [$reservaId, $sagaId] = reservaEnSaga();

    expect(revisarA(30))->toBe(1);

    expect(estadoReserva($reservaId))->toBe('FALLIDA')
        ->and(DB::scalar('SELECT motivo FROM reserva WHERE id = ?', [$reservaId]))->toContain('Gestión')
        ->and(estadoSaga($sagaId))->toBe('COMPENSADA')
        ->and(pasosDe($sagaId))->toBe(['COMPENSADO', 'FALLIDO', 'PENDIENTE', 'PENDIENTE'])
        ->and(ultimo($reservaId, 'reservas.AnularCargosReserva'))->toEqual(['reservaId' => $reservaId, 'unidadId' => Escenario::UNIDAD, 'alcance' => 'TODOS'])
        ->and(ultimo($reservaId, 'reservas.ReservaFallida'))->toMatchArray(['reservaId' => $reservaId, 'unidadId' => Escenario::UNIDAD])
        ->and(revisarA(3600))->toBe(0);
});

it('S2 un CargoAplicado que llega después de compensar no revive la saga', function () {
    [$reservaId, $sagaId] = reservaEnSaga();
    revisarA(30);

    expect(responder(RespuestaSaga::CARGO_APLICADO, $reservaId, $sagaId, ['c1']))->toBe(ProcesarRespuestaSaga::IGNORADA)
        ->and(estadoReserva($reservaId))->toBe('FALLIDA')
        ->and(tiposDe($reservaId))->not->toContain('reservas.EmitirPasesReserva');
});

it('S2 S3 si Acceso no emite los pases a tiempo, se revocan y se anulan los cargos; la reserva falla al confirmarse la anulación', function () {
    [$reservaId, $sagaId] = reservaEnSaga();
    responder(RespuestaSaga::CARGO_APLICADO, $reservaId, $sagaId, ['c1']);

    revisarA(30);

    expect(estadoSaga($sagaId))->toBe('COMPENSANDO')
        ->and(estadoReserva($reservaId))->toBe('SOLICITADA')
        ->and(ultimo($reservaId, 'reservas.RevocarPasesReserva'))->toBe(['reservaId' => $reservaId])
        ->and(ultimo($reservaId, 'reservas.AnularCargosReserva'))->toMatchArray(['alcance' => 'TODOS']);

    responder(RespuestaSaga::CARGO_ANULADO, $reservaId, $sagaId, ['c1']);
    responder(RespuestaSaga::PASE_REVOCADO, $reservaId, $sagaId);

    expect(estadoReserva($reservaId))->toBe('FALLIDA')
        ->and(DB::scalar('SELECT motivo FROM reserva WHERE id = ?', [$reservaId]))->toContain('Acceso')
        ->and(estadoSaga($sagaId))->toBe('COMPENSADA')
        ->and(pasosDe($sagaId))->toBe(['COMPENSADO', 'COMPENSADO', 'COMPENSADO', 'PENDIENTE'])
        ->and(ultimo($reservaId, 'reservas.ReservaFallida'))->toHaveKey('motivo');
});

it('S3 si la anulación no se confirma, se reenvía con espera exponencial y luego la saga pide revisión del ADMIN', function () {
    [$reservaId, $sagaId] = reservaEnSaga();
    responder(RespuestaSaga::CARGO_APLICADO, $reservaId, $sagaId, ['c1']);
    revisarA(30);   // compensa: anulación 1, espera 30 s

    expect(revisarA(59))->toBe(0)
        ->and(revisarA(60))->toBe(1)    // reenvío 1, espera 60 s
        ->and(revisarA(119))->toBe(0)
        ->and(revisarA(120))->toBe(1)   // reenvío 2, espera 120 s
        ->and(revisarA(240))->toBe(1);  // tercer intento: requiere revisión

    expect(estadoSaga($sagaId))->toBe('REQUIERE_REVISION')
        ->and(array_count_values(tiposDe($reservaId))['reservas.AnularCargosReserva'])->toBe(3)
        ->and(estadoReserva($reservaId))->toBe('SOLICITADA')
        ->and(revisarA(100000))->toBe(0);

    test()->withToken(Tokens::crear(['ADMIN']))->getJson('/api/sagas/requieren-revision')
        ->assertOk()->assertJsonFragment(['id' => $sagaId, 'reservaId' => $reservaId]);
});

it('S3 una anulación confirmada tarde cierra igual una saga en revisión', function () {
    [$reservaId, $sagaId] = reservaEnSaga();
    responder(RespuestaSaga::CARGO_APLICADO, $reservaId, $sagaId, ['c1']);
    foreach ([30, 60, 120, 240] as $segundos) {
        revisarA($segundos);
    }

    responder(RespuestaSaga::CARGO_ANULADO, $reservaId, $sagaId, ['c1']);

    expect(estadoSaga($sagaId))->toBe('COMPENSADA')
        ->and(estadoReserva($reservaId))->toBe('FALLIDA');
});

it('R7 el CargoAnulado de una cancelación no cambia una saga ya completada', function () {
    [$reservaId, $sagaId] = reservaEnSaga([]);
    responder(RespuestaSaga::CARGO_APLICADO, $reservaId, $sagaId);

    expect(responder(RespuestaSaga::CARGO_ANULADO, $reservaId, $sagaId, ['c1']))->toBe(ProcesarRespuestaSaga::IGNORADA)
        ->and(estadoSaga($sagaId))->toBe('COMPLETADA');
});

it('S4 los mensajes nuevos de la saga cumplen su contrato de contracts/events', function () {
    $carpeta = __DIR__.'/../../../../contracts/events/';
    if (! is_dir($carpeta)) {
        $this->markTestSkipped('repo abierto sin el espacio de trabajo condohub');
    }
    [$confirmada, $sagaConfirmada] = reservaEnSaga();
    responder(RespuestaSaga::CARGO_APLICADO, $confirmada, $sagaConfirmada, ['c1']);
    responder(RespuestaSaga::PASE_EMITIDO, $confirmada, $sagaConfirmada, ['QR1']);
    DB::update("UPDATE reserva SET estado = 'CANCELADA', motivo = 'libera el horario' WHERE id = ?", [$confirmada]);
    [$fallida, $sagaFallida] = reservaEnSaga();
    responder(RespuestaSaga::CARGO_APLICADO, $fallida, $sagaFallida, ['c1']);
    revisarA(30);
    responder(RespuestaSaga::CARGO_ANULADO, $fallida, $sagaFallida, ['c1']);

    $tipos = ['reservas.EmitirPasesReserva', 'reservas.ReservaConfirmada', 'reservas.RevocarPasesReserva', 'reservas.ReservaFallida'];
    foreach ($tipos as $tipo) {
        $esquema = json_decode((string) file_get_contents("{$carpeta}{$tipo}.schema.json"), true)['allOf'][1]['properties']['data'];
        $data = ultimo($tipo === 'reservas.ReservaConfirmada' || $tipo === 'reservas.EmitirPasesReserva' ? $confirmada : $fallida, $tipo);
        expect($data)->not->toBe([], $tipo)
            ->and(array_diff($esquema['required'], array_keys($data)))->toBe([], $tipo)
            ->and(array_diff(array_keys($data), array_keys($esquema['properties'])))->toBe([], $tipo);
    }
    $invitado = ultimo($confirmada, 'reservas.EmitirPasesReserva')['invitados'][0];
    expect(array_keys($invitado))->toEqualCanonicalizing(['invitadoId', 'nombre']);
});

it('S3 una saga que no se puede compensar por sus datos pasa a revisión sin frenar a las demás', function () {
    [$rota, $sagaRota] = reservaEnSaga([]);
    // Dato inconsistente: la reserva ya no está SOLICITADA aunque su saga sigue en curso (edición manual de la base).
    DB::update("UPDATE reserva SET estado = 'CONFIRMADA' WHERE id = ?", [$rota]);
    DB::update("UPDATE reserva SET estado = 'CANCELADA', motivo = 'editada a mano' WHERE id = ?", [$rota]);
    [$sana, $sagaSana] = reservaEnSaga([]);

    expect(revisarA(30))->toBe(2)
        ->and(estadoSaga($sagaRota))->toBe('REQUIERE_REVISION')
        ->and(DB::scalar('SELECT ultimo_error FROM saga_instancia WHERE id = ?', [$sagaRota]))->toContain('No se pudo compensar')
        ->and(tiposDe($rota))->toBe(['reservas.AplicarCargosReserva'])     // la compensación a medias se deshizo
        ->and(estadoSaga($sagaSana))->toBe('COMPENSADA')
        ->and(estadoReserva($sana))->toBe('FALLIDA')
        ->and(revisarA(3600))->toBe(0);
});
