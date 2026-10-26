<?php

use CondoHub\Reservas\Infrastructure\Http\Controllers\AreasController;
use CondoHub\Reservas\Infrastructure\Http\Problema;
use Illuminate\Support\Facades\Route;

// Todas las rutas exigen un JWT de Keycloak. `jwt` = cualquier rol; `jwt:A,B` = solo esos roles.
// Los roles de cada ruta son una propuesta derivada de las reglas (aún no existe contracts/openapi/reservas.yaml).

Route::get('salud', fn () => response()->json(['servicio' => 'reservas', 'estado' => 'OK']))
    ->middleware('jwt');

// R1: catálogo de áreas comunes.
Route::get('areas', AreasController::class)
    ->middleware('jwt');

// R2–R5, R8, O6: solicitar reserva (inicia la saga). No se crean reservas offline.
Route::post('reservas', fn () => Problema::noImplementado('La solicitud de reservas'))
    ->middleware('jwt:RESIDENTE,ADMIN');

Route::get('reservas', fn () => Problema::noImplementado('El listado de reservas'))
    ->middleware('jwt:RESIDENTE,ADMIN');

// R7: cancelación por el residente.
Route::post('reservas/{id}/cancelar', fn () => Problema::noImplementado('La cancelación de reservas'))
    ->whereUuid('id')
    ->middleware('jwt:RESIDENTE,ADMIN');

// R6: el ADMIN marca la reserva como FINALIZADA y se devuelve la garantía.
Route::post('reservas/{id}/finalizar', fn () => Problema::noImplementado('La finalización de reservas'))
    ->whereUuid('id')
    ->middleware('jwt:ADMIN');

// S3: sagas que requieren revisión del ADMIN.
Route::get('sagas/requieren-revision', fn () => Problema::noImplementado('El listado de sagas por revisar'))
    ->middleware('jwt:ADMIN');
