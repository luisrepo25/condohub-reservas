<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

// Las rutas implementadas deben coincidir con contracts/openapi/reservas.yaml (si el contrato está a mano).

const CONTRATO = __DIR__.'/../../../../contracts/openapi/reservas.yaml';

it('implementa exactamente las operaciones del contrato', function () {
    $contrato = Yaml::parseFile(CONTRATO);
    $esperadas = [];
    foreach ($contrato['paths'] as $ruta => $operaciones) {
        foreach (array_keys($operaciones) as $metodo) {
            if (in_array($metodo, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                $esperadas[] = strtoupper($metodo).' '.$ruta;
            }
        }
    }

    $implementadas = [];
    foreach (Route::getRoutes() as $ruta) {
        if (! str_starts_with($ruta->uri(), 'api/')) {
            continue;
        }
        foreach (array_diff($ruta->methods(), ['HEAD']) as $metodo) {
            $implementadas[] = $metodo.' /'.$ruta->uri();
        }
    }

    sort($esperadas);
    sort($implementadas);
    expect($implementadas)->toBe($esperadas);
})->skip(! file_exists(CONTRATO), 'repo abierto sin el espacio de trabajo condohub');
