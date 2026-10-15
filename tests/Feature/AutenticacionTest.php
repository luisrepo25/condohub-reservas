<?php

use CondoHub\Reservas\Application\Errors\ServicioDeIdentidadNoDisponible;
use CondoHub\Reservas\Application\Port\VerificadorToken;
use CondoHub\Reservas\Domain\Solicitante;
use Tests\Support\Tokens;

it('sin token responde 401 en problem+json', function () {
    $r = $this->getJson('/api/salud');

    $r->assertStatus(401)->assertJson(['status' => 401]);
    expect($r->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($r->headers->get('WWW-Authenticate'))->toBe('Bearer');
});

it('cualquier rol autenticado ve la salud', function (string $rol) {
    $this->conRol([$rol])->getJson('/api/salud')
        ->assertOk()
        ->assertExactJson(['servicio' => 'reservas', 'estado' => 'OK']);
})->with(['ADMIN', 'RESIDENTE', 'GUARDIA']);

it('rechaza con 401 un token inválido', function (string $token) {
    $this->withToken($token)->getJson('/api/salud')->assertStatus(401);
})->with([
    'firmado con otra clave' => fn () => Tokens::crear(['ADMIN'], ['claveAjena' => true]),
    'vencido' => fn () => Tokens::crear(['ADMIN'], ['expiraEn' => -60]),
    'de otro emisor' => fn () => Tokens::crear(['ADMIN'], ['issuer' => 'http://otro-realm/realms/x']),
    'basura' => fn () => 'esto.no.es-un-jwt',
    'vacío de segmentos' => fn () => 'abc',
]);

it('ignora los roles que no son de CondoHub', function () {
    $this->conRol(['superusuario'])->postJson('/api/reservas')->assertStatus(403);
});

it('responde 503 si Keycloak no está disponible, no un 401 engañoso', function () {
    $this->app->instance(VerificadorToken::class, new class implements VerificadorToken
    {
        public function verificar(string $token): Solicitante
        {
            throw new ServicioDeIdentidadNoDisponible('caído');
        }
    });

    $this->withToken('cualquiera')->getJson('/api/salud')->assertStatus(503);
});

it('devuelve problem+json también en rutas inexistentes', function () {
    $r = $this->conRol(['ADMIN'])->getJson('/api/no-existe');

    $r->assertStatus(404);
    expect($r->headers->get('Content-Type'))->toStartWith('application/problem+json');
});
