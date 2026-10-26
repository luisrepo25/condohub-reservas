<?php

// Estados de la reserva (R8), cancelación (R7), finalización (R6) y bloqueo optimista (O5).

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\Exception\VersionDesactualizada;
use CondoHub\Reservas\Domain\ValueObject\AlcanceAnulacion;
use CondoHub\Reservas\Domain\ValueObject\EstadoReserva;
use Tests\Support\Escenario;

it('R8 la saga lleva una reserva SOLICITADA a CONFIRMADA o a FALLIDA con motivo', function () {
    $confirmada = Escenario::solicitar();
    $confirmada->confirmar();

    $fallida = Escenario::solicitar();
    $fallida->fallar('Gestión no aplicó los cargos');

    expect($confirmada->estado())->toBe(EstadoReserva::Confirmada)
        ->and($confirmada->version())->toBe(1)
        ->and($fallida->estado())->toBe(EstadoReserva::Fallida)
        ->and($fallida->motivo())->toBe('Gestión no aplicó los cargos');
});

it('R8 una reserva FALLIDA exige motivo y no cambia si falta', function () {
    $reserva = Escenario::solicitar();

    expect(fn () => $reserva->fallar('  '))->toThrow(ReglaNegocioViolada::class);
    expect($reserva->estado())->toBe(EstadoReserva::Solicitada);
});

it('R8 rechaza transiciones inválidas', function () {
    $solicitada = Escenario::solicitar();
    expect(fn () => $solicitada->finalizar(false, 0))->toThrow(ReglaNegocioViolada::class, 'SOLICITADA no puede pasar a FINALIZADA');
    expect(fn () => $solicitada->cancelar(Escenario::ahora(), null, 0, Escenario::parametros()))
        ->toThrow(ReglaNegocioViolada::class, 'SOLICITADA no puede pasar a CANCELADA');

    $confirmada = Escenario::confirmada();
    expect(fn () => $confirmada->confirmar())->toThrow(ReglaNegocioViolada::class);

    $confirmada->finalizar(false, 1);
    expect(fn () => $confirmada->cancelar(Escenario::ahora(), null, 2, Escenario::parametros()))
        ->toThrow(ReglaNegocioViolada::class, 'FINALIZADA no puede pasar a CANCELADA');
});

it('R7 cancelar con 48 h o más de anticipación anula todos los cargos', function () {
    $reserva = Escenario::confirmada();

    $alcance = $reserva->cancelar(Escenario::local('2026-10-28 14:00'), 'Cambio de planes', 1, Escenario::parametros());

    expect($alcance)->toBe(AlcanceAnulacion::Todos)
        ->and($reserva->estado())->toBe(EstadoReserva::Cancelada)
        ->and($reserva->motivo())->toBe('Cambio de planes')
        ->and($reserva->version())->toBe(2);
});

it('R7 cancelar con menos de 48 h mantiene el costo de uso y devuelve solo la garantía', function () {
    $reserva = Escenario::confirmada();

    $alcance = $reserva->cancelar(Escenario::local('2026-10-29 15:00'), null, 1, Escenario::parametros());

    expect($alcance)->toBe(AlcanceAnulacion::SoloGarantia)
        ->and($reserva->motivo())->toBe('Cancelada por el solicitante');
});

it('R6 finalizar sin daños devuelve la garantía del salón', function () {
    $reserva = Escenario::confirmada();

    expect($reserva->finalizar(false, 1))->toBe(AlcanceAnulacion::SoloGarantia)
        ->and($reserva->estado())->toBe(EstadoReserva::Finalizada)
        ->and($reserva->conDanios())->toBeFalse();
});

it('R6 finalizar con daños no devuelve la garantía', function () {
    $reserva = Escenario::confirmada();

    expect($reserva->finalizar(true, 1))->toBeNull()
        ->and($reserva->conDanios())->toBeTrue();
});

it('R6 un área sin garantía no tiene nada que devolver al finalizar', function () {
    $reserva = Escenario::confirmada(Escenario::churrasquera());

    expect($reserva->finalizar(false, 1))->toBeNull();
});

it('O5 una versión vieja no cancela ni finaliza la reserva', function () {
    $reserva = Escenario::confirmada();

    expect(fn () => $reserva->cancelar(Escenario::ahora(), null, 0, Escenario::parametros()))
        ->toThrow(VersionDesactualizada::class);
    expect(fn () => $reserva->finalizar(false, 0))->toThrow(VersionDesactualizada::class);
    expect($reserva->estado())->toBe(EstadoReserva::Confirmada)
        ->and($reserva->version())->toBe(1);
});

it('F1 una reserva solo pertenece a su unidad', function () {
    $reserva = Escenario::confirmada();

    expect($reserva->perteneceA(Escenario::UNIDAD))->toBeTrue()
        ->and($reserva->perteneceA('00000000-0000-4000-8000-0000000000b2'))->toBeFalse();
});

it('R3 solo SOLICITADA y CONFIRMADA cuentan como activas', function () {
    $activas = array_filter(EstadoReserva::cases(), fn (EstadoReserva $e) => $e->esActiva());

    expect(array_values($activas))->toBe([EstadoReserva::Solicitada, EstadoReserva::Confirmada]);
});
