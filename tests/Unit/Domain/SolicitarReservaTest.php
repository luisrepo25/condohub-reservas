<?php

// Reglas al solicitar una reserva (dominio puro, sin base de datos).

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\ValueObject\BloqueHorario;
use CondoHub\Reservas\Domain\ValueObject\EstadoReserva;
use CondoHub\Reservas\Domain\ValueObject\Invitado;
use Tests\Support\Escenario;

function reglaVioladaEs(string $codigo): Closure
{
    return fn (ReglaNegocioViolada $e) => expect($e->codigo)->toBe($codigo);
}

it('R8 una reserva válida nace SOLICITADA en la versión 0', function () {
    $reserva = Escenario::solicitar();

    expect($reserva->estado())->toBe(EstadoReserva::Solicitada)
        ->and($reserva->version())->toBe(0)
        ->and($reserva->unidadId)->toBe(Escenario::UNIDAD)
        ->and($reserva->bloque->inicio->format('Y-m-d H:i e'))->toBe('2026-10-30 18:00 UTC');
});

it('R5 el salón copia su costo de uso y su garantía', function () {
    $reserva = Escenario::solicitar();

    expect((string) $reserva->costoUso)->toBe('500.00')
        ->and((string) $reserva->garantia)->toBe('300.00');
});

it('R5 las demás áreas solo copian el costo de uso', function () {
    $reserva = Escenario::solicitar(Escenario::churrasquera());

    expect((string) $reserva->costoUso)->toBe('50.00')
        ->and($reserva->garantia->esCero())->toBeTrue();
});

it('R1 un área inactiva no se reserva', function () {
    Escenario::solicitar(Escenario::salon(activa: false));
})->throws(ReglaNegocioViolada::class, 'no está disponible');

it('R1 los invitados no superan el aforo del área', function () {
    $invitados = array_map(fn (int $i) => new Invitado("Invitado {$i}"), range(1, 16));

    expect(fn () => Escenario::solicitar(Escenario::churrasquera(), invitados: $invitados))
        ->toThrow(reglaVioladaEs('R1'));
    expect(Escenario::solicitar(Escenario::churrasquera(), invitados: array_slice($invitados, 0, 15))->cantidadInvitados())
        ->toBe(15);
});

it('R2 rechaza una reserva que empieza antes de las 08:00', function () {
    Escenario::solicitar(bloque: Escenario::bloque('2026-10-30 07:00', '2026-10-30 10:00'));
})->throws(ReglaNegocioViolada::class, 'de 08:00 a 23:00');

it('R2 rechaza una reserva que termina después de las 23:00', function () {
    Escenario::solicitar(bloque: Escenario::bloque('2026-10-30 21:00', '2026-10-30 23:30'));
})->throws(ReglaNegocioViolada::class, 'de 08:00 a 23:00');

it('R2 rechaza una reserva que cruza la medianoche', function () {
    Escenario::solicitar(bloque: Escenario::bloque('2026-10-30 22:00', '2026-10-31 01:00'));
})->throws(ReglaNegocioViolada::class);

it('R2 acepta los extremos exactos 08:00 y 23:00', function () {
    $temprano = Escenario::solicitar(bloque: Escenario::bloque('2026-10-30 08:00', '2026-10-30 10:00'));
    $tarde = Escenario::solicitar(bloque: Escenario::bloque('2026-10-30 21:00', '2026-10-30 23:00'));

    expect($temprano->estado())->toBe(EstadoReserva::Solicitada)
        ->and($tarde->estado())->toBe(EstadoReserva::Solicitada);
});

it('R2 el horario se mide en hora local del condominio, no en UTC', function () {
    // 08:00 en La Paz son las 12:00 UTC: válido aunque en UTC parezca "tarde".
    $reserva = Escenario::solicitar(bloque: new BloqueHorario(
        new DateTimeImmutable('2026-10-30 12:00', new DateTimeZone('UTC')),
        new DateTimeImmutable('2026-10-30 14:00', new DateTimeZone('UTC')),
    ));

    expect($reserva->estado())->toBe(EstadoReserva::Solicitada);
});

it('R2 rechaza un bloque menor a 2 horas', function () {
    Escenario::solicitar(bloque: Escenario::bloque('2026-10-30 14:00', '2026-10-30 15:30'));
})->throws(ReglaNegocioViolada::class, 'bloque mínimo');

it('R2 el fin debe ser posterior al inicio', function () {
    Escenario::bloque('2026-10-30 14:00', '2026-10-30 14:00');
})->throws(ReglaNegocioViolada::class, 'posterior al inicio');

it('R3 rechaza una reserva con menos de 48 h de anticipación', function () {
    // "Ahora" es el lunes 26/10 12:00: el miércoles 28/10 a las 11:00 faltan 47 h.
    Escenario::solicitar(bloque: Escenario::bloque('2026-10-28 11:00', '2026-10-28 13:00'));
})->throws(ReglaNegocioViolada::class, '48 h');

it('R3 acepta una reserva con exactamente 48 h de anticipación', function () {
    $reserva = Escenario::solicitar(bloque: Escenario::bloque('2026-10-28 12:00', '2026-10-28 14:00'));

    expect($reserva->estado())->toBe(EstadoReserva::Solicitada);
});

it('R3 rechaza una reserva a más de 60 días', function () {
    Escenario::solicitar(bloque: Escenario::bloque('2026-12-26 10:00', '2026-12-26 12:00'));
})->throws(ReglaNegocioViolada::class, '60 días');

it('R3 una unidad con 2 reservas activas no puede pedir otra', function () {
    expect(fn () => Escenario::solicitar(activas: 2))->toThrow(reglaVioladaEs('R3'));
    expect(Escenario::solicitar(activas: 1)->estado())->toBe(EstadoReserva::Solicitada);
});

it('dos invitados con el mismo documento son una solicitud inválida', function () {
    Escenario::solicitar(invitados: [new Invitado('Ana', '123'), new Invitado('Luis', '123')]);
})->throws(InvalidArgumentException::class);
