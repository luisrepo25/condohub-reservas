<?php

// Matriz rol × endpoint. Permitido ⇒ llega a la lógica (ni 401 ni 403); no permitido ⇒ 403; sin token ⇒ 401.

const UUID_X = '11111111-1111-4111-8111-111111111111';

dataset('endpoints', [
    'R1_catalogo_de_areas' => ['GET', '/api/areas', ['ADMIN', 'RESIDENTE', 'GUARDIA']],
    'R3_solicitar_reserva' => ['POST', '/api/reservas', ['RESIDENTE', 'ADMIN']],
    'R3_listar_reservas' => ['GET', '/api/reservas', ['RESIDENTE', 'ADMIN']],
    'R7_cancelar_reserva' => ['POST', '/api/reservas/'.UUID_X.'/cancelar', ['RESIDENTE', 'ADMIN']],
    'R6_finalizar_reserva' => ['POST', '/api/reservas/'.UUID_X.'/finalizar', ['ADMIN']],
    'S3_sagas_por_revisar' => ['GET', '/api/sagas/requieren-revision', ['ADMIN']],
]);

it('sin token es 401', function (string $metodo, string $ruta) {
    $this->json($metodo, $ruta)->assertStatus(401);
})->with('endpoints');

it('cada rol solo usa lo que le toca', function (string $metodo, string $ruta, array $permitidos) {
    foreach (['ADMIN', 'RESIDENTE', 'GUARDIA'] as $rol) {
        $r = $this->conRol([$rol])->json($metodo, $ruta);

        if (in_array($rol, $permitidos, true)) {
            expect($r->getStatusCode())->not->toBeIn([401, 403], "{$rol} en {$metodo} {$ruta}");
        } else {
            expect($r->getStatusCode())->toBe(403, "{$rol} en {$metodo} {$ruta}");
            expect($r->headers->get('Content-Type'))->toStartWith('application/problem+json');
        }
    }
})->with('endpoints');

it('los 501 se identifican como NO_IMPLEMENTADO', function () {
    $this->conRol(['ADMIN'])->getJson('/api/areas')->assertStatus(501)->assertJson(['code' => 'NO_IMPLEMENTADO']);
});

it('un id que no es uuid no existe como ruta', function () {
    $this->conRol(['ADMIN'])->postJson('/api/reservas/no-es-uuid/finalizar')->assertStatus(404);
});
