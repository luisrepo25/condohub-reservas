<?php

namespace App\Providers;

use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Application\Port\VerificadorToken;
use CondoHub\Reservas\Domain\Port\AreaComunRepository;
use CondoHub\Reservas\Infrastructure\Gateway\FinanzasGatewayGraphQL;
use CondoHub\Reservas\Infrastructure\Persistence\PgAreaComunRepository;
use CondoHub\Reservas\Infrastructure\Security\KeycloakJwtVerificador;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

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

        // R4: Gestión con el token de quien hace la petición (se lee al consultar, no al construir).
        $this->app->bind(FinanzasGateway::class, fn ($app): FinanzasGateway => new FinanzasGatewayGraphQL(
            $app->make(HttpFactory::class),
            fn (): ?string => $app->make(Request::class)->bearerToken(),
            $app->make(LoggerInterface::class),
            (string) config('gestion.graphql_url'),
            (int) config('gestion.timeout_segundos'),
            (int) config('gestion.reintentos'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
