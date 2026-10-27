<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Gateway;

use Closure;
use CondoHub\Reservas\Application\Errors\FinanzasNoDisponible;
use CondoHub\Reservas\Application\Errors\TokenInvalido;
use CondoHub\Reservas\Application\Errors\UnidadInexistente;
use CondoHub\Reservas\Application\Port\FinanzasGateway;
use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

/**
 * FinanzasGateway sobre la API GraphQL de Gestión (contracts/graphql/schema.graphql), a través del gateway.
 * Reenvía el token de quien reserva: Gestión decide F1 con sus propias reglas y Reservas no guarda credenciales.
 * Es una lectura idempotente, así que se reintenta ante un fallo de conexión; nunca se reintenta una respuesta.
 */
final class FinanzasGatewayGraphQL implements FinanzasGateway
{
    private const CONSULTA = 'query ExpensasVencidasParaReservar($unidadId: ID!) '
        .'{ estadoDeCuenta(unidadId: $unidadId) { expensasVencidas } }';

    /** @param Closure(): ?string $tokenDelSolicitante */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Closure $tokenDelSolicitante,
        private readonly LoggerInterface $log,
        private readonly string $url,
        private readonly int $timeoutSegundos,
        private readonly int $reintentos,
    ) {}

    public function expensasVencidas(string $unidadId): int
    {
        $token = ($this->tokenDelSolicitante)();
        if ($token === null || $token === '') {
            throw new FinanzasNoDisponible('No hay un token del solicitante para consultar a Gestión');
        }

        try {
            $respuesta = $this->http->withToken($token)
                ->acceptJson()
                ->timeout($this->timeoutSegundos)
                ->retry($this->reintentos + 1, 200, static fn (\Throwable $e): bool => $e instanceof ConnectionException, throw: false)
                ->post($this->url, ['query' => self::CONSULTA, 'variables' => ['unidadId' => $unidadId]]);
        } catch (ConnectionException $e) {
            $this->log->warning('Gestión no responde a la consulta de morosidad', ['unidadId' => $unidadId, 'error' => $e->getMessage()]);
            throw new FinanzasNoDisponible('Gestión no responde; no se puede comprobar la morosidad (R4)', previous: $e);
        }

        return $this->interpretar($respuesta, $unidadId);
    }

    private function interpretar(Response $respuesta, string $unidadId): int
    {
        if ($respuesta->status() === 401) {
            throw new TokenInvalido('El token del solicitante ya no es válido para Gestión');
        }
        if (! $respuesta->successful()) {
            $this->log->warning('Gestión respondió un error HTTP a la consulta de morosidad', [
                'unidadId' => $unidadId, 'status' => $respuesta->status(),
            ]);
            throw new FinanzasNoDisponible("Gestión respondió {$respuesta->status()}");
        }

        $codigo = $respuesta->json('errors.0.extensions.code');
        if ($codigo !== null) {
            throw $this->errorDeGestion(is_string($codigo) ? $codigo : '', $unidadId);
        }

        $vencidas = $respuesta->json('data.estadoDeCuenta.expensasVencidas');
        if (! is_int($vencidas) || $vencidas < 0) {
            $this->log->error('Gestión respondió sin expensasVencidas válidas (¿cambió el contrato?)', ['unidadId' => $unidadId]);
            throw new FinanzasNoDisponible('Gestión respondió algo que no cumple su contrato');
        }

        return $vencidas;
    }

    private function errorDeGestion(string $codigo, string $unidadId): \Exception
    {
        return match ($codigo) {
            'F1', 'FORBIDDEN' => new ReglaNegocioViolada('F1', 'Solo puedes reservar para una unidad tuya'),
            'NOT_FOUND' => new UnidadInexistente("La unidad {$unidadId} no existe"),
            default => $this->noDisponible($codigo, $unidadId),
        };
    }

    private function noDisponible(string $codigo, string $unidadId): FinanzasNoDisponible
    {
        $this->log->warning('Gestión respondió un error GraphQL inesperado', ['unidadId' => $unidadId, 'code' => $codigo]);

        return new FinanzasNoDisponible("Gestión respondió el error {$codigo}");
    }
}
