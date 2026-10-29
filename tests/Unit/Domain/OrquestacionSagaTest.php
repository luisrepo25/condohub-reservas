<?php

// S1–S3: cómo avanza y se compensa la saga de una reserva, sin infraestructura. Los reintentos de cada paso los hace
// su consumidor (Gestión, Acceso); aquí cada paso tiene un plazo y, si vence, se compensa en orden inverso.

use CondoHub\Reservas\Domain\Model\SagaReserva;
use Tests\Support\Escenario;

function sagaNueva(): SagaReserva
{
    return SagaReserva::iniciar('s1', 'r1', 'e-cargos', Escenario::ahora(), 30, ['unidadCodigo' => 'A-101']);
}

function enPases(): SagaReserva
{
    return sagaNueva()->cargosAplicados(['c1', 'c2'], 'e-pases', Escenario::ahora(), 30);
}

function segundosDesdeAhora(SagaReserva $saga): int
{
    return $saga->proximoReintento->getTimestamp() - Escenario::ahora()->getTimestamp();
}

it('S1 la saga guarda el código de la unidad para el paso 3', function () {
    expect(sagaNueva()->unidadCodigo())->toBe('A-101')
        ->and(SagaReserva::iniciar('s1', 'r1', 'e', Escenario::ahora(), 30)->unidadCodigo())->toBeNull();
});

it('S2 con los cargos aplicados y con invitados, sigue el paso 3 con su propio plazo', function () {
    $saga = enPases();

    expect($saga->estado)->toBe('EN_CURSO')
        ->and($saga->esperaPases())->toBeTrue()
        ->and($saga->comandoEnCursoId)->toBe('e-pases')
        ->and($saga->contexto['cargoIds'])->toBe(['c1', 'c2'])
        ->and(segundosDesdeAhora($saga))->toBe(30)
        ->and($saga->pasosActuales())->toBe([1 => 'OK', 2 => 'OK', 3 => 'EN_CURSO', 4 => 'PENDIENTE']);
});

it('S2 sin invitados el paso 3 no aplica y la saga termina bien con los cargos', function () {
    $saga = sagaNueva()->cargosAplicados([], null, Escenario::ahora(), 30);

    expect($saga->estado)->toBe('COMPLETADA')
        ->and($saga->terminada())->toBeTrue()
        ->and($saga->pasosActuales())->toBe([1 => 'OK', 2 => 'OK', 3 => 'OMITIDO', 4 => 'OK']);
});

it('S2 con los pases emitidos la saga termina bien (paso 4: el aviso)', function () {
    $saga = enPases()->pasesEmitidos(['QR1'], Escenario::ahora());

    expect($saga->estado)->toBe('COMPLETADA')
        ->and($saga->contexto['pases'])->toBe(['QR1'])
        ->and($saga->pasosActuales())->toBe([1 => 'OK', 2 => 'OK', 3 => 'OK', 4 => 'OK']);
});

it('S2 el plazo de un paso vence solo después de su espera', function () {
    $saga = sagaNueva();

    expect($saga->vencio(Escenario::ahora()->modify('+29 seconds')))->toBeFalse()
        ->and($saga->vencio(Escenario::ahora()->modify('+30 seconds')))->toBeTrue()
        ->and(enPases()->pasesEmitidos([], Escenario::ahora())->vencio(Escenario::ahora()->modify('+1 day')))->toBeFalse();
});

it('S2 si Gestión no aplica los cargos a tiempo, la saga se compensa sin esperar: no quedó nada confirmado', function () {
    $saga = sagaNueva()->cargosNoAplicados(Escenario::ahora());

    expect($saga->estado)->toBe('COMPENSADA')
        ->and($saga->ultimoError)->toContain('Gestión')
        ->and($saga->pasosActuales())->toBe([1 => 'COMPENSADO', 2 => 'FALLIDO', 3 => 'PENDIENTE', 4 => 'PENDIENTE']);
});

it('S2 S3 si Acceso no emite los pases a tiempo, se espera la anulación de los cargos ya aplicados', function () {
    $saga = enPases()->pasesNoEmitidos('e-anular', Escenario::ahora(), 30);

    expect($saga->estado)->toBe('COMPENSANDO')
        ->and($saga->esperaAnulacion())->toBeTrue()
        ->and($saga->comandoEnCursoId)->toBe('e-anular')
        ->and($saga->ultimoError)->toContain('Acceso')
        ->and($saga->pasosActuales())->toBe([1 => 'OK', 2 => 'OK', 3 => 'FALLIDO', 4 => 'PENDIENTE']);
});

it('S3 con la anulación confirmada la saga queda compensada', function () {
    $saga = enPases()->pasesNoEmitidos('e-anular', Escenario::ahora(), 30)->cargosAnulados(Escenario::ahora());

    expect($saga->estado)->toBe('COMPENSADA')
        ->and($saga->pasosActuales())->toBe([1 => 'COMPENSADO', 2 => 'COMPENSADO', 3 => 'FALLIDO', 4 => 'PENDIENTE'])
        ->and($saga->pasesRevocados()?->estadoDelPaso(3))->toBe('COMPENSADO');
});

it('S3 la anulación se reenvía con espera exponencial y, agotados los intentos, la saga requiere revisión', function () {
    $saga = enPases()->pasesNoEmitidos('e-anular', Escenario::ahora(), 30);

    $primera = $saga->reintentarAnulacion('e-2', Escenario::ahora(), 30, 3);
    $segunda = $primera->reintentarAnulacion('e-3', Escenario::ahora(), 30, 3);
    $agotada = $segunda->reintentarAnulacion('e-4', Escenario::ahora(), 30, 3);

    expect([$primera->estado, $primera->intentosCompensacion, segundosDesdeAhora($primera), $primera->comandoEnCursoId])
        ->toBe(['COMPENSANDO', 1, 60, 'e-2'])
        ->and([$segunda->estado, $segunda->intentosCompensacion, segundosDesdeAhora($segunda)])->toBe(['COMPENSANDO', 2, 120])
        ->and($agotada->estado)->toBe('REQUIERE_REVISION')
        ->and($agotada->estadoDelPaso(2))->toBe('COMPENSACION_FALLIDA')
        ->and($agotada->ultimoError)->toContain('3 intentos')
        ->and($agotada->vencio(Escenario::ahora()->modify('+1 day')))->toBeFalse();
});

it('S3 si la anulación llega tarde, después de pasar a revisión, la saga igual se compensa', function () {
    $revision = enPases()->pasesNoEmitidos('e', Escenario::ahora(), 30)->reintentarAnulacion('e2', Escenario::ahora(), 30, 1);

    expect($revision->estado)->toBe('REQUIERE_REVISION')
        ->and($revision->esperaAnulacion())->toBeTrue()
        ->and($revision->cargosAnulados(Escenario::ahora())->estado)->toBe('COMPENSADA');
});

it('S2 una respuesta que la saga no espera se rechaza en el dominio', function (Closure $paso) {
    $paso();
})->with([
    'pases antes de los cargos' => [fn () => sagaNueva()->pasesEmitidos([], Escenario::ahora())],
    'cargos dos veces' => [fn () => enPases()->cargosAplicados([], null, Escenario::ahora(), 30)],
    'anulación sin compensar' => [fn () => enPases()->cargosAnulados(Escenario::ahora())],
    'vencer una saga terminada' => [fn () => sagaNueva()->cargosAplicados([], null, Escenario::ahora(), 30)->cargosNoAplicados(Escenario::ahora())],
])->throws(DomainException::class);

it('una revocación que no se pidió no cambia la saga', function () {
    expect(enPases()->pasesRevocados())->toBeNull();
});

it('S3 una saga inconsistente queda en revisión con el motivo y no vuelve a vencer', function () {
    $saga = sagaNueva()->aRevision('No se pudo compensar: la reserva ya no está SOLICITADA', Escenario::ahora());

    expect($saga->estado)->toBe('REQUIERE_REVISION')
        ->and($saga->ultimoError)->toContain('SOLICITADA')
        ->and($saga->vencio(Escenario::ahora()->modify('+1 day')))->toBeFalse();
});
