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
 * Reenvía el token de quien hace la petición: Gestión decide F1 con sus propias reglas y Reservas no guarda
 * credenciales. Son lecturas idempotentes: se reintenta ante un fallo de conexión; nunca se reintenta una respuesta.
 */
final class FinanzasGatewayGraphQL implements FinanzasGateway
{
    private const EXPENSAS_VENCIDAS = 'query ExpensasVencidasParaReservar($unidadId: ID!) '
        .'{ estadoDeCuenta(unidadId: $unidadId) { expensasVencidas } }';

    private const MIS_UNIDADES = 'query UnidadesDelSolicitante { miPerfil { unidades { unidadId } } }';

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
        $respuesta = $this->consultar(self::EXPENSAS_VENCIDAS, ['unidadId' => $unidadId], ['unidadId' => $unidadId]);
        $vencidas = $respuesta->json('data.estadoDeCuenta.expensasVencidas');
        if (! is_int($vencidas) || $vencidas < 0) {
            throw $this->fueraDeContrato('estadoDeCuenta.expensasVencidas', ['unidadId' => $unidadId]);
        }

        return $vencidas;
    }

    public function unidadesDelSolicitante(): array
    {
        $unidades = $this->consultar(self::MIS_UNIDADES, [], [])->json('data.miPerfil.unidades');
        if (! is_array($unidades)) {
            throw $this->fueraDeContrato('miPerfil.unidades', []);
        }
        $ids = [];
        foreach ($unidades as $unidad) {
            $id = is_array($unidad) ? ($unidad['unidadId'] ?? null) : null;
            if (! is_string($id) || $id === '') {
                throw $this->fueraDeContrato('miPerfil.unidades[].unidadId', []);
            }
            $ids[] = strtolower($id);
        }

        return $ids;
    }

    /**
     * POST a Gestión con el token del solicitante. Traduce lo que no es una respuesta válida a un error del dominio o
     * de la aplicación.
     *
     * @param  array<string, string>  $variables
     * @param  array<string, string>  $contexto  datos para los logs (nunca el token)
     */
    private function consultar(string $consulta, array $variables, array $contexto): Response
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
                ->post($this->url, ['query' => $consulta, 'variables' => (object) $variables]);
        } catch (ConnectionException $e) {
            $this->log->warning('Gestión no responde', [...$contexto, 'error' => $e->getMessage()]);
            throw new FinanzasNoDisponible('Gestión no responde', previous: $e);
        }

        if ($respuesta->status() === 401) {
            throw new TokenInvalido('El token del solicitante ya no es válido para Gestión');
        }
        if (! $respuesta->successful()) {
            $this->log->warning('Gestión respondió un error HTTP', [...$contexto, 'status' => $respuesta->status()]);
            throw new FinanzasNoDisponible("Gestión respondió {$respuesta->status()}");
        }
        $codigo = $respuesta->json('errors.0.extensions.code');
        if ($codigo !== null) {
            throw $this->errorDeGestion(is_string($codigo) ? $codigo : '', $contexto);
        }

        return $respuesta;
    }

    /** @param array<string, string> $contexto */
    private function errorDeGestion(string $codigo, array $contexto): \Exception
    {
        $unidad = $contexto['unidadId'] ?? '';

        return match ($codigo) {
            'F1', 'FORBIDDEN' => new ReglaNegocioViolada('F1', 'Solo puedes reservar para una unidad tuya'),
            'NOT_FOUND' => new UnidadInexistente("La unidad {$unidad} no existe"),
            default => $this->noDisponible("Gestión respondió el error {$codigo}", [...$contexto, 'code' => $codigo]),
        };
    }

    /** @param array<string, string> $contexto */
    private function fueraDeContrato(string $campo, array $contexto): FinanzasNoDisponible
    {
        $this->log->error("Gestión respondió sin {$campo} válido (¿cambió el contrato?)", $contexto);

        return new FinanzasNoDisponible('Gestión respondió algo que no cumple su contrato');
    }

    /** @param array<string, string> $contexto */
    private function noDisponible(string $mensaje, array $contexto): FinanzasNoDisponible
    {
        $this->log->warning($mensaje, $contexto);

        return new FinanzasNoDisponible($mensaje);
    }
}
