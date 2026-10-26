<?php

namespace App\Providers;

use CondoHub\Reservas\Application\Port\VerificadorToken;
use CondoHub\Reservas\Domain\Port\AreaComunRepository;
use CondoHub\Reservas\Infrastructure\Persistence\PgAreaComunRepository;
use CondoHub\Reservas\Infrastructure\Security\KeycloakJwtVerificador;
use Illuminate\Database\ConnectionInterface;
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

        $this->app->bind(AreaComunRepository::class, fn ($app): AreaComunRepository => new PgAreaComunRepository(
            $app->make(ConnectionInterface::class),
        ));
    }

    public function boot(): void
    {
        //
    }
}
