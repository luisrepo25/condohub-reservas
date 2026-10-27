<?php

// Adaptador de Gestión (R4, F1) con respuestas simuladas: qué envía y cómo traduce cada respuesta del contrato GraphQL.

use CondoHub\Reservas\Application\Errors\FinanzasNoDisponible;
use CondoHub\Reservas\Application\Errors\TokenInvalido;
use CondoHub\Reservas\Application\Errors\UnidadInexistente;
use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Infrastructure\Gateway\FinanzasGatewayGraphQL;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Log\NullLogger;

const URL_GESTION = 'http://gateway.prueba/gestion/graphql';
const UNIDAD_X = '22222222-2222-4222-8222-222222222222';

function gestion(?string $token = 'token-del-residente'): FinanzasGatewayGraphQL
{
    return new FinanzasGatewayGraphQL(app(HttpFactory::class), fn () => $token, new NullLogger, URL_GESTION, 2, 1);
}

function respuestaGestion(array $cuerpo, int $status = 200): void
{
    Http::fake([URL_GESTION => Http::response($cuerpo, $status)]);
}

it('R4 devuelve las expensas vencidas que informa Gestión', function () {
    respuestaGestion(['data' => ['estadoDeCuenta' => ['expensasVencidas' => 3]]]);

    expect(gestion()->expensasVencidas(UNIDAD_X))->toBe(3);
});

it('F1 consulta con el token de quien reserva y la unidad como variable', function () {
    respuestaGestion(['data' => ['estadoDeCuenta' => ['expensasVencidas' => 0]]]);

    gestion()->expensasVencidas(UNIDAD_X);

    Http::assertSent(fn (Request $r) => $r->url() === URL_GESTION
        && $r->hasHeader('Authorization', 'Bearer token-del-residente')
        && str_contains($r['query'], 'estadoDeCuenta(unidadId: $unidadId) { expensasVencidas }')
        && json_decode($r->body(), true)['variables'] === ['unidadId' => UNIDAD_X]);
});

it('F1 una unidad ajena se rechaza con el código F1', function (string $codigoGestion) {
    respuestaGestion(['data' => null, 'errors' => [['message' => 'x', 'extensions' => ['code' => $codigoGestion]]]]);

    expect(fn () => gestion()->expensasVencidas(UNIDAD_X))
        ->toThrow(fn (ReglaNegocioViolada $e) => expect($e->codigo)->toBe('F1'));
})->with(['F1', 'FORBIDDEN']);

it('una unidad que no existe en Finanzas es UnidadInexistente', function () {
    respuestaGestion(['data' => null, 'errors' => [['message' => 'x', 'extensions' => ['code' => 'NOT_FOUND']]]]);

    gestion()->expensasVencidas(UNIDAD_X);
})->throws(UnidadInexistente::class);

it('un 401 de Gestión es un token inválido', function () {
    respuestaGestion(['message' => 'Unauthorized'], 401);

    gestion()->expensasVencidas(UNIDAD_X);
})->throws(TokenInvalido::class);

it('un error 5xx, un error GraphQL inesperado o una respuesta fuera de contrato es FinanzasNoDisponible', function (array $cuerpo, int $status) {
    respuestaGestion($cuerpo, $status);

    gestion()->expensasVencidas(UNIDAD_X);
})->with([
    'error 502 del gateway' => [['message' => 'Bad gateway'], 502],
    'error GraphQL desconocido' => [['errors' => [['message' => 'x', 'extensions' => ['code' => 'INTERNAL_ERROR']]]], 200],
    'sin el campo' => [['data' => ['estadoDeCuenta' => []]], 200],
    'valor negativo' => [['data' => ['estadoDeCuenta' => ['expensasVencidas' => -1]]], 200],
    'valor como texto' => [['data' => ['estadoDeCuenta' => ['expensasVencidas' => '2']]], 200],
])->throws(FinanzasNoDisponible::class);

it('ante un fallo de conexión reintenta una vez y luego es FinanzasNoDisponible', function () {
    $intentos = 0;
    Http::fake([URL_GESTION => function () use (&$intentos) {
        $intentos++;
        throw new ConnectionException('Connection refused');
    }]);

    expect(fn () => gestion()->expensasVencidas(UNIDAD_X))->toThrow(FinanzasNoDisponible::class);
    expect($intentos)->toBe(2);
});

it('un fallo de conexión pasajero se recupera con el reintento', function () {
    Http::fakeSequence(URL_GESTION)
        ->pushFailedConnection()
        ->push(['data' => ['estadoDeCuenta' => ['expensasVencidas' => 1]]]);

    expect(gestion()->expensasVencidas(UNIDAD_X))->toBe(1);
});

it('sin token del solicitante no consulta a Gestión', function () {
    Http::fake();

    expect(fn () => gestion(null)->expensasVencidas(UNIDAD_X))->toThrow(FinanzasNoDisponible::class);
    Http::assertNothingSent();
});

it('F1 las unidades del solicitante salen de miPerfil, con su token', function () {
    respuestaGestion(['data' => ['miPerfil' => ['unidades' => [['unidadId' => 'AAAAAAAA-0000-4000-8000-000000000001'], ['unidadId' => UNIDAD_X]]]]]);

    expect(gestion()->unidadesDelSolicitante())->toBe(['aaaaaaaa-0000-4000-8000-000000000001', UNIDAD_X]);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer token-del-residente')
        && str_contains($r['query'], 'miPerfil { unidades { unidadId } }'));
});

it('F1 una cuenta sin unidades enlazadas no tiene unidades', function () {
    respuestaGestion(['data' => ['miPerfil' => ['unidades' => []]]]);

    expect(gestion()->unidadesDelSolicitante())->toBe([]);
});

it('miPerfil fuera de contrato es FinanzasNoDisponible', function (array $cuerpo) {
    respuestaGestion($cuerpo);

    gestion()->unidadesDelSolicitante();
})->with([
    'sin unidades' => [['data' => ['miPerfil' => []]]],
    'unidad sin id' => [['data' => ['miPerfil' => ['unidades' => [['codigo' => 'A-101']]]]]],
])->throws(FinanzasNoDisponible::class);
