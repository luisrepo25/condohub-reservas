<?php

// Integración real Reservas → gateway → Gestión (R4, F1) con tokens reales de Keycloak y los datos demo de Gestión.
// Solo corre con el compose de infra/ levantado: RESERVAS_INTEGRACION=1 vendor/bin/pest --group=integracion
// (usa GESTION_GRAPHQL_URL y KEYCLOAK_ISSUER de .env). Sin esa variable se omite: el CI no tiene a Gestión.

use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use Illuminate\Support\Facades\Http;

function tokenReal(string $usuario): string
{
    $respuesta = Http::asForm()->post(config('keycloak.issuer').'/protocol/openid-connect/token', [
        'grant_type' => 'password',
        'client_id' => 'condohub-spa',
        'username' => $usuario,
        'password' => env('USUARIOS_PRUEBA_PASSWORD', 'Condohub123!'),
    ]);

    return (string) $respuesta->throw()->json('access_token');
}

/** Consulta GraphQL directa a Gestión, solo para preparar los datos de la prueba. */
function consultarGestion(string $token, string $consulta): mixed
{
    return Http::withToken($token)->post((string) config('gestion.graphql_url'), ['query' => $consulta])->throw()->json('data');
}

/** El adaptador real, con el token del usuario como si viniera en la petición. */
function finanzasCon(string $token): FinanzasGateway
{
    request()->headers->set('Authorization', "Bearer {$token}");

    return app(FinanzasGateway::class);
}

beforeEach(function () {
    if (env('RESERVAS_INTEGRACION') !== '1') {
        $this->markTestSkipped('Integración con Gestión: activar con RESERVAS_INTEGRACION=1 y el compose levantado');
    }
    // phpunit.xml apunta a una Gestión inexistente para que ninguna otra prueba salga a la red: aquí va la real.
    config(['gestion.graphql_url' => env('GESTION_URL_INTEGRACION', 'http://localhost:8090/gestion/graphql'), 'gestion.reintentos' => 1]);
});

it('F1 Gestión dice qué unidades son del residente', function () {
    $token = tokenReal('residente@condohub.test');
    $esperadas = array_column(consultarGestion($token, '{ miPerfil { unidades { unidadId } } }')['miPerfil']['unidades'], 'unidadId');

    expect(finanzasCon($token)->unidadesDelSolicitante())->toBe($esperadas)->not->toBeEmpty();
})->group('integracion');

it('R4 el residente consulta la morosidad de su propia unidad', function () {
    $token = tokenReal('residente@condohub.test');
    $miUnidad = consultarGestion($token, '{ miPerfil { unidades { unidadId } } }')['miPerfil']['unidades'][0]['unidadId'];

    expect(finanzasCon($token)->expensasVencidas($miUnidad))->toBeInt()->toBeGreaterThanOrEqual(0);
})->group('integracion');

it('F1 el residente no puede consultar una unidad ajena', function () {
    $admin = tokenReal('admin@condohub.test');
    $ajena = consultarGestion($admin, '{ morosos { unidadId } }')['morosos'][0]['unidadId'];

    expect(fn () => finanzasCon(tokenReal('residente@condohub.test'))->expensasVencidas($ajena))
        ->toThrow(fn (ReglaNegocioViolada $e) => expect($e->codigo)->toBe('F1'));
})->group('integracion');

it('R4 el ADMIN ve las expensas vencidas de una unidad morosa', function () {
    $admin = tokenReal('admin@condohub.test');
    $moroso = collect(consultarGestion($admin, '{ morosos { unidadId expensasVencidas } }')['morosos'])
        ->firstWhere('expensasVencidas', '>=', 2);

    expect(finanzasCon($admin)->expensasVencidas($moroso['unidadId']))->toBe($moroso['expensasVencidas']);
})->group('integracion');
