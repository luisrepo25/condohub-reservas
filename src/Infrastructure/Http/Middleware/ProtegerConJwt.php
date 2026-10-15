<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Http\Middleware;

use Closure;
use CondoHub\Reservas\Application\Autorizacion;
use CondoHub\Reservas\Application\Errors\TokenInvalido;
use CondoHub\Reservas\Application\Port\VerificadorToken;
use CondoHub\Reservas\Domain\Rol;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica con el JWT de Keycloak y autoriza por rol. Uso en rutas: `jwt` (cualquier rol) o `jwt:RESIDENTE,ADMIN`.
 * El solicitante queda en `$request->attributes->get('solicitante')`.
 */
final class ProtegerConJwt
{
    public function __construct(private readonly VerificadorToken $verificador) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            throw new TokenInvalido('Falta el header Authorization: Bearer');
        }

        $solicitante = $this->verificador->verificar($token);
        if ($roles !== []) {
            Autorizacion::exigirAlgunRol($solicitante, ...array_map(static fn (string $r): Rol => Rol::from($r), $roles));
        }
        $request->attributes->set('solicitante', $solicitante);

        return $next($request);
    }
}
