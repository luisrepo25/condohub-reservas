<?php

use CondoHub\Reservas\Infrastructure\Http\Controllers\AreasController;
use CondoHub\Reservas\Infrastructure\Http\Controllers\ReservasController;
use CondoHub\Reservas\Infrastructure\Http\Controllers\SagasController;
use Illuminate\Support\Facades\Route;

// Todas las rutas exigen un JWT de Keycloak. `jwt` = cualquier rol; `jwt:A,B` = solo esos roles.
// Roles por ruta: contracts/openapi/reservas.yaml (confirmados por Erick el 26/10/2026).

Route::get('salud', fn () => response()->json(['servicio' => 'reservas', 'estado' => 'OK']))
    ->middleware('jwt');

// R1: catálogo de áreas comunes.
Route::get('areas', AreasController::class)
    ->middleware('jwt');

// R2–R5, R8, O6: solicitar reserva (inicia la saga). No se crean reservas offline.
Route::post('reservas', [ReservasController::class, 'solicitar'])
    ->middleware('jwt:RESIDENTE,ADMIN');

Route::get('reservas', [ReservasController::class, 'listar'])
    ->middleware('jwt:RESIDENTE,ADMIN');

// R7: cancelación por el residente.
Route::post('reservas/{id}/cancelar', [ReservasController::class, 'cancelar'])
    ->whereUuid('id')
    ->middleware('jwt:RESIDENTE,ADMIN');

// R6: el ADMIN marca la reserva como FINALIZADA y se devuelve la garantía.
Route::post('reservas/{id}/finalizar', [ReservasController::class, 'finalizar'])
    ->whereUuid('id')
    ->middleware('jwt:ADMIN');

// S3: sagas que requieren revisión del ADMIN.
Route::get('sagas/requieren-revision', SagasController::class)
    ->middleware('jwt:ADMIN');
