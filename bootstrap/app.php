<?php

use CondoHub\Reservas\Application\Errors\AreaInexistente;
use CondoHub\Reservas\Application\Errors\ClaveIdempotenciaUsada;
use CondoHub\Reservas\Application\Errors\FinanzasNoDisponible;
use CondoHub\Reservas\Application\Errors\ReservaNoEncontrada;
use CondoHub\Reservas\Application\Errors\ServicioDeIdentidadNoDisponible;
use CondoHub\Reservas\Application\Errors\SinPermiso;
use CondoHub\Reservas\Application\Errors\TokenInvalido;
use CondoHub\Reservas\Application\Errors\UnidadInexistente;
use CondoHub\Reservas\Domain\Exception\DatoInvalido;
use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\Exception\VersionDesactualizada;
use CondoHub\Reservas\Infrastructure\Console\ConsumirSagaCommand;
use CondoHub\Reservas\Infrastructure\Console\PublicarOutboxCommand;
use CondoHub\Reservas\Infrastructure\Http\Middleware\ProtegerConJwt;
use CondoHub\Reservas\Infrastructure\Http\Problema;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withCommands([PublicarOutboxCommand::class, ConsumirSagaCommand::class])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['jwt' => ProtegerConJwt::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Es una API: siempre JSON, en application/problem+json (RFC 7807).
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(fn (TokenInvalido $e) => Problema::de(401, 'No autenticado', null, null, ['WWW-Authenticate' => 'Bearer']));
        $exceptions->render(fn (SinPermiso $e) => Problema::de(403, 'Sin permiso', $e->getMessage()));
        $exceptions->render(fn (ServicioDeIdentidadNoDisponible $e) => Problema::de(503, 'Servicio de identidad no disponible'));
        // Reglas de negocio: `code` = código de la regla. F1 (la unidad no es del solicitante) es falta de permiso.
        $exceptions->render(fn (ReglaNegocioViolada $e) => $e->codigo === 'F1'
            ? Problema::de(403, 'Sin permiso', $e->getMessage(), 'F1')
            : Problema::de(422, 'Regla de negocio violada', $e->getMessage(), $e->codigo));
        $exceptions->render(fn (VersionDesactualizada $e) => Problema::de(409, 'Versión desactualizada', $e->getMessage(), 'O5'));
        $exceptions->render(fn (UnidadInexistente $e) => Problema::de(422, 'Unidad inexistente', $e->getMessage()));
        $exceptions->render(fn (FinanzasNoDisponible $e) => Problema::de(
            503, 'Gestión no disponible', 'No se pudo consultar a Gestión (unidades o morosidad); reintenta en unos segundos', null, ['Retry-After' => '5'],
        ));
        $exceptions->render(fn (ReservaNoEncontrada $e) => Problema::de(404, 'No encontrado', $e->getMessage()));
        $exceptions->render(fn (AreaInexistente $e) => Problema::de(422, 'Área inexistente', $e->getMessage(), 'R1'));
        $exceptions->render(fn (ClaveIdempotenciaUsada $e) => Problema::de(409, 'Idempotency-Key ya usada', $e->getMessage(), 'O3'));
        // Solicitud mal formada (contrato: 400 SolicitudInvalida), sea por la validación HTTP o por un dato del dominio.
        $exceptions->render(fn (ValidationException $e) => Problema::de(400, 'Solicitud inválida', $e->validator->errors()->first()));
        $exceptions->render(fn (DatoInvalido $e) => Problema::de(400, 'Solicitud inválida', $e->getMessage()));
        $exceptions->render(fn (NotFoundHttpException $e) => Problema::de(404, 'No encontrado'));
        $exceptions->render(fn (MethodNotAllowedHttpException $e) => Problema::de(405, 'Método no permitido'));
    })->create();
