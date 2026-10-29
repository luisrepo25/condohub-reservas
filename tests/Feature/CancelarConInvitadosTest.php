<?php

// R7: al cancelar una reserva confirmada, los pases de sus invitados se revocan (Acceso, A2) en la misma transacción
// que la anulación de los cargos (S4). "Ahora" = lunes 26/10/2026 12:00 en La Paz; la reserva es el viernes 30/10.

use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Application\Port\Reloj;
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

/** @param list<array{nombre: string}> $invitados */
function confirmadaCon(array $invitados): string
{
    $id = test()->withToken(Tokens::crear(['ADMIN']))->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/reservas', [
            'areaId' => (string) DB::scalar("SELECT id FROM area_comun WHERE codigo = 'SALON'"),
            'unidadId' => Escenario::UNIDAD,
            'inicio' => '2026-10-30T14:00:00-04:00',
            'fin' => '2026-10-30T18:00:00-04:00',
            'invitados' => $invitados,
        ])->assertStatus(202)->json('id');
    DB::update("UPDATE reserva SET estado = 'CONFIRMADA', version = 1 WHERE id = ?", [$id]);

    return $id;
}

/** @return list<string> */
function mensajesDeCancelacion(string $id): array
{
    return array_map(static fn (object $f): string => $f->routing_key, DB::select(
        "SELECT routing_key FROM outbox WHERE aggregate_id = ? AND routing_key <> 'reservas.AplicarCargosReserva' ORDER BY occurred_at, secuencia",
        [$id],
    ));
}

function cancelarComoAdmin(string $id): void
{
    test()->withToken(Tokens::crear(['ADMIN']))->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/reservas/{$id}/cancelar", ['version' => 1])->assertOk();
}

it('R7 A2 cancelar una reserva con invitados anula los cargos y revoca sus pases, con la misma saga', function () {
    $id = confirmadaCon([['nombre' => 'Ana Pérez'], ['nombre' => 'Luis Rojas']]);

    cancelarComoAdmin($id);

    $revocar = DB::selectOne("SELECT payload::text AS payload, saga_id FROM outbox WHERE aggregate_id = ? AND routing_key = 'reservas.RevocarPasesReserva'", [$id]);
    expect(mensajesDeCancelacion($id))->toBe(['reservas.AnularCargosReserva', 'reservas.RevocarPasesReserva'])
        ->and(json_decode($revocar->payload, true))->toBe(['reservaId' => $id])
        ->and($revocar->saga_id)->toBe(DB::scalar('SELECT id FROM saga_instancia WHERE reserva_id = ?', [$id]));
});

it('R7 sin invitados no hay pases que revocar', function () {
    $id = confirmadaCon([]);

    cancelarComoAdmin($id);

    expect(mensajesDeCancelacion($id))->toBe(['reservas.AnularCargosReserva']);
});
