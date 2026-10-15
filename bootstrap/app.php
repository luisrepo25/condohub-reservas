<?php

use CondoHub\Reservas\Application\Errors\ServicioDeIdentidadNoDisponible;
use CondoHub\Reservas\Application\Errors\SinPermiso;
use CondoHub\Reservas\Application\Errors\TokenInvalido;
use CondoHub\Reservas\Infrastructure\Http\Middleware\ProtegerConJwt;
use CondoHub\Reservas\Infrastructure\Http\Problema;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['jwt' => ProtegerConJwt::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Es una API: siempre JSON, en application/problem+json (RFC 7807).
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(fn (TokenInvalido $e) => Problema::de(401, 'No autenticado', null, null, ['WWW-Authenticate' => 'Bearer']));
        $exceptions->render(fn (SinPermiso $e) => Problema::de(403, 'Sin permiso', $e->getMessage()));
        $exceptions->render(fn (ServicioDeIdentidadNoDisponible $e) => Problema::de(503, 'Servicio de identidad no disponible'));
        $exceptions->render(fn (NotFoundHttpException $e) => Problema::de(404, 'No encontrado'));
        $exceptions->render(fn (MethodNotAllowedHttpException $e) => Problema::de(405, 'Método no permitido'));
    })->create();
