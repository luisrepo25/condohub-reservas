<?php

// S5: el consumidor de la saga solo acepta respuestas con el sobre y los campos de su contrato (contracts/events).

use CondoHub\Reservas\Application\Saga\RespuestaSaga;
use CondoHub\Reservas\Infrastructure\Messaging\LectorRespuestaSaga;
use CondoHub\Reservas\Infrastructure\Messaging\MensajeSagaInvalido;

const RESERVA_LEIDA = '44444444-4444-4444-8444-000000000001';
const SAGA_LEIDA = '33333333-3333-4333-8333-000000000001';

/** @param array<string, mixed> $data */
function sobreDeRespuesta(string $tipo, array $data, ?string $sagaId = SAGA_LEIDA): string
{
    return (string) json_encode([
        'eventId' => '11111111-1111-4111-8111-000000000001', 'type' => $tipo, 'occurredAt' => '2026-10-28T12:00:00Z',
        'sagaId' => $sagaId, 'version' => 1, 'data' => ['reservaId' => RESERVA_LEIDA, ...$data],
    ]);
}

it('S5 lee CargoAplicado con los ids de los cargos y la saga a la que responde', function () {
    $r = LectorRespuestaSaga::desde(sobreDeRespuesta('finanzas.CargoAplicado', ['unidadId' => 'u', 'cargoIds' => ['c1', 'c2']]));

    expect($r)->toEqual(new RespuestaSaga('11111111-1111-4111-8111-000000000001', RespuestaSaga::CARGO_APLICADO, SAGA_LEIDA, RESERVA_LEIDA, ['c1', 'c2']));
});

it('S5 lee PaseEmitido con los códigos de los pases y PaseRevocado sin ids', function () {
    $emitido = LectorRespuestaSaga::desde(sobreDeRespuesta('acceso.PaseEmitido', ['pases' => [['invitadoId' => 'i1', 'codigo' => 'QR1']]]));
    $revocado = LectorRespuestaSaga::desde(sobreDeRespuesta('acceso.PaseRevocado', ['cantidad' => 2], null));

    expect($emitido->ids)->toBe(['QR1'])
        ->and($revocado->ids)->toBe([])
        ->and($revocado->sagaId)->toBeNull();
});

it('S5 un mensaje fuera de contrato se rechaza (va a la cola de fallidos)', function (string $cuerpo) {
    LectorRespuestaSaga::desde($cuerpo);
})->with([
    'no es JSON' => ['hola'],
    'tipo ajeno' => [sobreDeRespuesta('finanzas.PagoConfirmado', ['cargoIds' => []])],
    'sin reservaId' => [(string) json_encode(['eventId' => '11111111-1111-4111-8111-000000000001', 'type' => 'finanzas.CargoAnulado', 'data' => ['cargoIds' => []]])],
    'sin cargoIds' => [sobreDeRespuesta('finanzas.CargoAplicado', [])],
    'pases sin código' => [sobreDeRespuesta('acceso.PaseEmitido', ['pases' => [['invitadoId' => 'i1']]])],
    'sagaId que no es uuid' => [sobreDeRespuesta('finanzas.CargoAnulado', ['cargoIds' => []], 'x')],
])->throws(MensajeSagaInvalido::class);
