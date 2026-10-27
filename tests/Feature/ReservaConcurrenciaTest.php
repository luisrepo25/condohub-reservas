<?php

// Carreras entre peticiones simultáneas (R2, O3): lo que el dominio no alcanza a ver lo frena PostgreSQL, y el
// repositorio lo traduce a la regla correcta en vez de un error 500.

use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Application\Port\Reloj;
use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\Exception\ReservaYaRegistrada;
use CondoHub\Reservas\Domain\Model\AreaComun;
use CondoHub\Reservas\Domain\Model\Reserva;
use CondoHub\Reservas\Domain\Port\ReservaRepository;
use CondoHub\Reservas\Domain\ValueObject\BloqueHorario;
use CondoHub\Reservas\Domain\ValueObject\IdempotencyKey;
use CondoHub\Reservas\Infrastructure\Persistence\PgReservaRepository;
use Illuminate\Support\Facades\DB;
use Tests\Support\BaseDeDatos;
use Tests\Support\Escenario;
use Tests\Support\FinanzasFalsa;
use Tests\Support\RelojFijo;
use Tests\Support\Tokens;

uses(BaseDeDatos::class);

/** Reserva del salón de la base, con id, clave y horario a elección (como si la creara otra petición). */
function reservaDelSalon(string $id, string $clave, string $inicio = '2026-10-30 14:00', string $fin = '2026-10-30 18:00'): Reserva
{
    $salon = (string) DB::scalar("SELECT id FROM area_comun WHERE codigo = 'SALON'");
    $area = Escenario::salon();

    return Reserva::solicitar(
        $id,
        new AreaComun($salon, 'SALON', 'Salón de eventos', $area->tipo, 80, '08:00', '23:00', 2, $area->costoUso, $area->garantia, true),
        Escenario::UNIDAD,
        'sub-residente',
        Escenario::bloque($inicio, $fin),
        [],
        IdempotencyKey::de($clave),
        Escenario::ahora(),
        0,
        Escenario::parametros(),
    );
}

it('R2 si dos pedidos del mismo horario pasan el chequeo a la vez, la base frena al segundo con el código R2', function () {
    $repositorio = app(PgReservaRepository::class);
    $repositorio->guardarNueva(reservaDelSalon('c0000000-0000-4000-8000-000000000001', '11111111-1111-4111-8111-111111111111'));

    expect(fn () => $repositorio->guardarNueva(reservaDelSalon(
        'c0000000-0000-4000-8000-000000000002', '22222222-2222-4222-8222-222222222222', '2026-10-30 17:00', '2026-10-30 19:00',
    )))->toThrow(fn (ReglaNegocioViolada $e) => expect($e->codigo)->toBe('R2'));
});

it('O3 si dos reintentos con la misma clave llegan a guardar, el segundo es ReservaYaRegistrada', function () {
    $repositorio = app(PgReservaRepository::class);
    $repositorio->guardarNueva(reservaDelSalon('c0000000-0000-4000-8000-000000000001', '11111111-1111-4111-8111-111111111111'));

    $repositorio->guardarNueva(reservaDelSalon(
        'c0000000-0000-4000-8000-000000000003', '11111111-1111-4111-8111-111111111111', '2026-11-02 10:00', '2026-11-02 12:00',
    ));
})->throws(ReservaYaRegistrada::class);

it('O3 la reserva guardada se lee igual: horario en UTC, montos e invitados', function () {
    $repositorio = app(PgReservaRepository::class);
    $original = reservaDelSalon('c0000000-0000-4000-8000-000000000001', '11111111-1111-4111-8111-111111111111');
    $repositorio->guardarNueva($original);

    $leida = $repositorio->porIdempotencyKey(IdempotencyKey::de('11111111-1111-4111-8111-111111111111'));

    expect($leida)->not->toBeNull()
        ->and($leida->id)->toBe($original->id)
        ->and($leida->bloque->inicio->format(DATE_ATOM))->toBe('2026-10-30T18:00:00+00:00')
        ->and($leida->bloque->fin->format(DATE_ATOM))->toBe('2026-10-30T22:00:00+00:00')
        ->and((string) $leida->costoUso)->toBe('500.00')
        ->and((string) $leida->garantia)->toBe('300.00')
        ->and($leida->estado()->value)->toBe('SOLICITADA')
        ->and($leida->version())->toBe(0);
});

it('O3 un reintento simultáneo con la misma clave responde la reserva del primero, no un R2', function () {
    $this->app->instance(FinanzasGateway::class, new FinanzasFalsa);
    $this->app->instance(Reloj::class, new RelojFijo(Escenario::ahora()));
    $clave = '33333333-3333-4333-8333-333333333333';
    $cuerpo = [
        'areaId' => (string) DB::scalar("SELECT id FROM area_comun WHERE codigo = 'SALON'"),
        'unidadId' => Escenario::UNIDAD,
        'inicio' => '2026-10-30T14:00:00-04:00',
        'fin' => '2026-10-30T18:00:00-04:00',
    ];
    $token = Tokens::crear(['RESIDENTE'], ['sub' => 'sub-residente']);
    $primera = $this->withToken($token)->withHeader('Idempotency-Key', $clave)->postJson('/api/reservas', $cuerpo)->assertStatus(202);

    // El segundo reintento no vio la reserva al entrar (la primera aún no se había confirmado): simula esa ventana.
    $real = app(PgReservaRepository::class);
    $this->app->instance(ReservaRepository::class, new class($real) implements ReservaRepository
    {
        private bool $primeraLectura = true;

        public function __construct(private readonly PgReservaRepository $real) {}

        public function porIdempotencyKey(IdempotencyKey $clave): ?Reserva
        {
            if ($this->primeraLectura) {
                $this->primeraLectura = false;

                return null;
            }

            return $this->real->porIdempotencyKey($clave);
        }

        public function contarActivasBloqueandoUnidad(string $unidadId): int
        {
            return $this->real->contarActivasBloqueandoUnidad($unidadId);
        }

        public function bloquesOcupados(string $areaId, BloqueHorario $pedido): array
        {
            return $this->real->bloquesOcupados($areaId, $pedido);
        }

        public function guardarNueva(Reserva $reserva): void
        {
            $this->real->guardarNueva($reserva);
        }
    });

    $segunda = $this->withToken($token)->withHeader('Idempotency-Key', $clave)->postJson('/api/reservas', $cuerpo)->assertStatus(202);

    expect($segunda->json())->toBe($primera->json())
        ->and(DB::scalar('SELECT count(*) FROM reserva'))->toBe(1)
        ->and(DB::scalar('SELECT count(*) FROM outbox'))->toBe(1);
});
