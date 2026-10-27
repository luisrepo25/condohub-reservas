<?php

// S4: el sobre que se publica cumple contracts/events/_sobre.schema.json.

use CondoHub\Reservas\Application\Outbox\MensajePendiente;
use CondoHub\Reservas\Infrastructure\Messaging\SobreEvento;

const SOBRE = __DIR__.'/../../../../contracts/events/_sobre.schema.json';

function mensajeDePrueba(?string $sagaId = 'f0000000-0000-4000-8000-000000000001'): MensajePendiente
{
    return new MensajePendiente(
        'e0000000-0000-4000-8000-000000000001',
        'reservas.AnularCargosReserva',
        $sagaId,
        new DateTimeImmutable('2026-10-30 14:05:09', new DateTimeZone('America/La_Paz')),
        '{"reservaId": "b0000000-0000-4000-8000-000000000001", "unidadId": "c0000000-0000-4000-8000-000000000001", "alcance": "TODOS"}',
    );
}

it('S4 el sobre lleva eventId, type, occurredAt en UTC, sagaId, version y data', function () {
    $sobre = json_decode(SobreEvento::json(mensajeDePrueba()), true);

    expect($sobre)->toBe([
        'eventId' => 'e0000000-0000-4000-8000-000000000001',
        'type' => 'reservas.AnularCargosReserva',
        'occurredAt' => '2026-10-30T18:05:09Z',
        'sagaId' => 'f0000000-0000-4000-8000-000000000001',
        'version' => 1,
        'data' => ['reservaId' => 'b0000000-0000-4000-8000-000000000001', 'unidadId' => 'c0000000-0000-4000-8000-000000000001', 'alcance' => 'TODOS'],
    ]);
});

it('S4 un mensaje sin saga lleva sagaId null', function () {
    expect(json_decode(SobreEvento::json(mensajeDePrueba(null)), true)['sagaId'])->toBeNull();
});

it('S4 el sobre cumple el esquema común del contrato', function () {
    $esquema = json_decode((string) file_get_contents(SOBRE), true);
    $sobre = json_decode(SobreEvento::json(mensajeDePrueba()), true);

    expect(array_diff($esquema['required'], array_keys($sobre)))->toBe([])
        ->and(array_diff(array_keys($sobre), array_keys($esquema['properties'])))->toBe([])
        ->and($sobre['type'])->toMatch('/'.$esquema['properties']['type']['pattern'].'/')
        ->and($sobre['version'])->toBeGreaterThanOrEqual($esquema['properties']['version']['minimum'])
        ->and($sobre['data'])->toBeArray();
})->skip(! file_exists(SOBRE), 'repo abierto sin el espacio de trabajo condohub');
