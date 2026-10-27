<?php

// S1, S2: estado inicial de la saga de una reserva.

use CondoHub\Reservas\Domain\Event\AplicarCargosReserva;
use CondoHub\Reservas\Domain\Model\SagaReserva;
use Tests\Support\Escenario;

it('S1 la saga nace con el horario bloqueado (paso 1) y los cargos en curso (paso 2)', function () {
    $saga = SagaReserva::iniciar('s1', 'r1', 'e1', Escenario::ahora(), 30);

    expect($saga->estado)->toBe('EN_CURSO')
        ->and($saga->pasoActual)->toBe(SagaReserva::PASO_APLICAR_CARGOS)
        ->and($saga->comandoEnCursoId)->toBe('e1')
        ->and(array_map($saga->estadoDelPaso(...), [1, 2, 3, 4]))->toBe(['OK', 'EN_CURSO', 'PENDIENTE', 'PENDIENTE']);
});

it('S2 el orquestador revisa el paso en curso cuando vence su espera', function () {
    $saga = SagaReserva::iniciar('s1', 'r1', 'e1', Escenario::ahora(), 30);

    expect($saga->proximoReintento->getTimestamp() - Escenario::ahora()->getTimestamp())->toBe(30);
});

it('S2 la espera de un paso debe ser positiva', function () {
    SagaReserva::iniciar('s1', 'r1', 'e1', Escenario::ahora(), 0);
})->throws(InvalidArgumentException::class);

it('R5 el comando AplicarCargosReserva lleva los montos copiados del área', function () {
    $reserva = Escenario::solicitar();

    $comando = AplicarCargosReserva::de('e1', 's1', $reserva, Escenario::salon(), Escenario::ahora());

    expect($comando->data)->toBe([
        'reservaId' => $reserva->id,
        'unidadId' => Escenario::UNIDAD,
        'areaComun' => 'SALON',
        'costoUso' => '500.00',
        'garantia' => '300.00',
    ])->and(AplicarCargosReserva::TIPO)->toBe('reservas.AplicarCargosReserva');
});
