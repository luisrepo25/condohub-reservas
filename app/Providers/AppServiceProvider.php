<?php

namespace App\Providers;

use CondoHub\Reservas\Application\Port\ConsultaSagas;
use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Application\Port\Outbox;
use CondoHub\Reservas\Application\Port\OutboxPendiente;
use CondoHub\Reservas\Application\Port\PublicadorEventos;
use CondoHub\Reservas\Application\Port\Reloj;
use CondoHub\Reservas\Application\Port\Transaccion;
use CondoHub\Reservas\Application\Port\VerificadorToken;
use CondoHub\Reservas\Domain\Port\AreaComunRepository;
use CondoHub\Reservas\Domain\Port\ParametroRepository;
use CondoHub\Reservas\Domain\Port\ReservaRepository;
use CondoHub\Reservas\Domain\Port\SagaRepository;
use CondoHub\Reservas\Infrastructure\Gateway\FinanzasGatewayGraphQL;
use CondoHub\Reservas\Infrastructure\Messaging\PgOutbox;
use CondoHub\Reservas\Infrastructure\Messaging\PgOutboxPendiente;
use CondoHub\Reservas\Infrastructure\Messaging\PublicadorRabbitMq;
use CondoHub\Reservas\Infrastructure\Persistence\PgAreaComunRepository;
use CondoHub\Reservas\Infrastructure\Persistence\PgConsultaSagas;
use CondoHub\Reservas\Infrastructure\Persistence\PgParametroRepository;
use CondoHub\Reservas\Infrastructure\Persistence\PgReservaRepository;
use CondoHub\Reservas\Infrastructure\Persistence\PgSagaRepository;
use CondoHub\Reservas\Infrastructure\Persistence\TransaccionLaravel;
use CondoHub\Reservas\Infrastructure\Reloj\RelojSistema;
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

        // Solicitar reserva (paso 1 de la saga): repositorios, outbox y transacción sobre la misma conexión.
        $this->app->bind(ReservaRepository::class, PgReservaRepository::class);
        $this->app->bind(SagaRepository::class, PgSagaRepository::class);
        $this->app->bind(Outbox::class, PgOutbox::class);
        $this->app->bind(Transaccion::class, TransaccionLaravel::class);
        $this->app->bind(Reloj::class, RelojSistema::class);
        $this->app->bind(ParametroRepository::class, fn ($app): ParametroRepository => new PgParametroRepository(
            $app->make(ConnectionInterface::class),
            (string) config('reservas.zona_horaria'),
        ));

        // S3: consulta de sagas atascadas. S4: publicador del outbox (una conexión a RabbitMQ por proceso).
        $this->app->bind(ConsultaSagas::class, PgConsultaSagas::class);
        $this->app->bind(OutboxPendiente::class, PgOutboxPendiente::class);
        $this->app->singleton(PublicadorEventos::class, fn ($app): PublicadorEventos => new PublicadorRabbitMq(
            (string) config('rabbitmq.host'),
            (int) config('rabbitmq.puerto'),
            (string) config('rabbitmq.usuario'),
            (string) config('rabbitmq.clave'),
            (string) config('rabbitmq.vhost'),
            (string) config('rabbitmq.exchange'),
            (float) config('rabbitmq.espera_segundos'),
            $app->make(LoggerInterface::class),
        ));
    }

    public function boot(): void
    {
        //
    }
}
