<?php

namespace App\Providers;

use CondoHub\Reservas\Application\Port\VerificadorToken;
use CondoHub\Reservas\Infrastructure\Security\KeycloakJwtVerificador;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Conecta los puertos de la aplicación con su infraestructura. */
    public function register(): void
    {
        $this->app->singleton(VerificadorToken::class, fn (): VerificadorToken => KeycloakJwtVerificador::desdeJwks(
            (string) config('keycloak.issuer'),
            (string) config('keycloak.jwks'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
