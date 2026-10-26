<?php

// GET /api/areas de punta a punta: ruta, JWT, caso de uso y PostgreSQL con las áreas de la migración (R1, R5).

use Illuminate\Support\Facades\DB;
use Tests\Support\BaseDeDatos;

uses(BaseDeDatos::class);

it('R1 lista las áreas activas con la forma del contrato', function () {
    $respuesta = $this->conRol(['RESIDENTE'])->getJson('/api/areas')->assertOk();

    expect(array_column($respuesta->json(), 'codigo'))->toBe(['CHURR-1', 'CHURR-2', 'PISCINA', 'SALON']);
    expect($respuesta->json('3'))->toMatchArray([
        'codigo' => 'SALON',
        'nombre' => 'Salón de eventos',
        'tipo' => 'SALON_EVENTOS',
        'aforo' => 80,
        'horaApertura' => '08:00',
        'horaCierre' => '23:00',
        'bloqueMinimoHoras' => 2,
        'costoUso' => '500.00',
        'garantia' => '300.00',
    ]);
    expect(array_keys($respuesta->json('0')))->toBe([
        'id', 'codigo', 'nombre', 'tipo', 'aforo', 'horaApertura', 'horaCierre', 'bloqueMinimoHoras', 'costoUso', 'garantia',
    ]);
});

it('R5 solo el salón tiene garantía', function () {
    $areas = collect($this->conRol(['ADMIN'])->getJson('/api/areas')->json())->keyBy('codigo');

    expect($areas['SALON']['garantia'])->toBe('300.00')
        ->and($areas['PISCINA']['garantia'])->toBe('0.00')
        ->and($areas['CHURR-1']['garantia'])->toBe('0.00')
        ->and($areas['CHURR-1']['costoUso'])->toBe('50.00');
});

it('R1 un área inactiva no aparece en el catálogo', function () {
    DB::update("UPDATE area_comun SET activa = false WHERE codigo = 'PISCINA'");

    $codigos = array_column($this->conRol(['GUARDIA'])->getJson('/api/areas')->json(), 'codigo');

    expect($codigos)->not->toContain('PISCINA')->toHaveCount(3);
});
