<?php

// R4: la morosidad la informa Gestión; el límite es un parámetro de Reservas.

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\Service\PoliticaMorosidad;
use CondoHub\Reservas\Domain\ValueObject\ParametrosReserva;
use Tests\Support\Escenario;

it('R4 una unidad al día o con 1 expensa vencida puede reservar', function (int $vencidas) {
    (new PoliticaMorosidad)->exigirPuedeReservar($vencidas, Escenario::parametros());

    expect(true)->toBeTrue();
})->with([0, 1]);

it('R4 una unidad con 2 o más expensas vencidas no puede reservar', function (int $vencidas) {
    expect(fn () => (new PoliticaMorosidad)->exigirPuedeReservar($vencidas, Escenario::parametros()))
        ->toThrow(fn (ReglaNegocioViolada $e) => expect($e->codigo)->toBe('R4')
            ->and($e->getMessage())->toContain("{$vencidas} expensas vencidas"));
})->with([2, 3, 12]);

it('R4 el límite es un parámetro, no una constante', function () {
    $estricto = new ParametrosReserva(48, 60, 2, 48, 1, Escenario::zona());

    expect(fn () => (new PoliticaMorosidad)->exigirPuedeReservar(1, $estricto))->toThrow(ReglaNegocioViolada::class);
});

it('R4 el límite de morosidad debe ser al menos 1', function () {
    new ParametrosReserva(48, 60, 2, 48, 0, Escenario::zona());
})->throws(InvalidArgumentException::class);
