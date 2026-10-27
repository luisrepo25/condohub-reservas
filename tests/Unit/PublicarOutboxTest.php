<?php

// S4: el publicador del outbox con dobles (sin base ni broker): orden, marcado y qué pasa si el broker falla.

use CondoHub\Reservas\Application\Errors\PublicacionFallida;
use CondoHub\Reservas\Application\Outbox\MensajePendiente;
use CondoHub\Reservas\Application\Port\OutboxPendiente;
use CondoHub\Reservas\Application\Port\PublicadorEventos;
use CondoHub\Reservas\Application\Port\Transaccion;
use CondoHub\Reservas\Application\UseCase\PublicarOutbox;

final class OutboxEnMemoria implements OutboxPendiente
{
    /** @var array<string, array{publicado: bool, intentos: int}> */
    public array $filas = [];

    public function __construct(string ...$ids)
    {
        foreach ($ids as $id) {
            $this->filas[$id] = ['publicado' => false, 'intentos' => 0];
        }
    }

    public function tomarPendientes(int $limite): array
    {
        $pendientes = array_keys(array_filter($this->filas, fn (array $f) => ! $f['publicado']));

        return array_map(
            fn (string $id) => new MensajePendiente($id, 'reservas.AplicarCargosReserva', null, new DateTimeImmutable('@0'), '{}'),
            array_slice($pendientes, 0, $limite),
        );
    }

    public function marcarPublicado(string $id): void
    {
        $this->filas[$id]['publicado'] = true;
        $this->filas[$id]['intentos']++;
    }

    public function registrarIntentoFallido(string $id): void
    {
        $this->filas[$id]['intentos']++;
    }
}

final class BrokerFalso implements PublicadorEventos
{
    /** @var list<string> */
    public array $recibidos = [];

    public function __construct(private readonly ?string $fallaCon = null) {}

    public function publicar(MensajePendiente $mensaje): void
    {
        if ($mensaje->id === $this->fallaCon) {
            throw new PublicacionFallida('broker caído');
        }
        $this->recibidos[] = $mensaje->id;
    }
}

final class SinTransaccion implements Transaccion
{
    public function ejecutar(callable $trabajo): mixed
    {
        return $trabajo();
    }
}

it('S4 publica los pendientes en orden y los marca publicados', function () {
    $outbox = new OutboxEnMemoria('m1', 'm2', 'm3');
    $broker = new BrokerFalso;

    $resultado = (new PublicarOutbox($outbox, $broker, new SinTransaccion))->ejecutar(50);

    expect($broker->recibidos)->toBe(['m1', 'm2', 'm3'])
        ->and($resultado->publicados)->toBe(3)
        ->and($resultado->fallido)->toBeNull()
        ->and(array_column($outbox->filas, 'publicado'))->toBe([true, true, true]);
});

it('S4 respeta el lote: el resto queda para la vuelta siguiente', function () {
    $outbox = new OutboxEnMemoria('m1', 'm2', 'm3');
    $publicar = new PublicarOutbox($outbox, new BrokerFalso, new SinTransaccion);

    expect($publicar->ejecutar(2)->publicados)->toBe(2)
        ->and($publicar->ejecutar(2)->publicados)->toBe(1)
        ->and($publicar->ejecutar(2)->publicados)->toBe(0);
});

it('S4 si el broker falla, el mensaje queda pendiente con su intento y los siguientes esperan (orden)', function () {
    $outbox = new OutboxEnMemoria('m1', 'm2', 'm3');
    $broker = new BrokerFalso(fallaCon: 'm2');

    $resultado = (new PublicarOutbox($outbox, $broker, new SinTransaccion))->ejecutar(50);

    expect($broker->recibidos)->toBe(['m1'])
        ->and($resultado->publicados)->toBe(1)
        ->and($resultado->fallido)->toBe('m2')
        ->and($outbox->filas['m2'])->toBe(['publicado' => false, 'intentos' => 1])
        ->and($outbox->filas['m3'])->toBe(['publicado' => false, 'intentos' => 0]);
});

it('S4 el lote debe ser de al menos 1', function () {
    (new PublicarOutbox(new OutboxEnMemoria, new BrokerFalso, new SinTransaccion))->ejecutar(0);
})->throws(InvalidArgumentException::class);
