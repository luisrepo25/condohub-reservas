<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Security;

use Closure;
use CondoHub\Reservas\Application\Errors\ServicioDeIdentidadNoDisponible;
use CondoHub\Reservas\Application\Errors\TokenInvalido;
use CondoHub\Reservas\Application\Port\VerificadorToken;
use CondoHub\Reservas\Domain\Rol;
use CondoHub\Reservas\Domain\Solicitante;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Valida firma RS256, `exp` e `iss` de un JWT de Keycloak. Los roles salen de `realm_access.roles`. */
final class KeycloakJwtVerificador implements VerificadorToken
{
    /** @param Closure(bool): array<string, Key> $claves recibe `true` cuando debe refrescar las claves (rotación) */
    public function __construct(private readonly string $issuer, private readonly Closure $claves) {}

    public static function desdeJwks(string $issuer, string $jwksUri, int $ttlSegundos = 3600): self
    {
        return new self($issuer, static function (bool $refrescar) use ($jwksUri, $ttlSegundos): array {
            if ($refrescar) {
                Cache::forget('keycloak.jwks');
            }
            try {
                /** @var array{keys: list<array<string, mixed>>} $jwks */
                $jwks = Cache::remember('keycloak.jwks', $ttlSegundos, static function () use ($jwksUri): array {
                    return Http::timeout(5)->get($jwksUri)->throw()->json();
                });
            } catch (ConnectionException|RequestException $e) {
                throw new ServicioDeIdentidadNoDisponible($e->getMessage(), 0, $e);
            }

            return JWK::parseKeySet($jwks, 'RS256');
        });
    }

    public function verificar(string $token): Solicitante
    {
        $claims = $this->decodificar($token);
        if (($claims->iss ?? null) !== $this->issuer || ! isset($claims->sub)) {
            throw new TokenInvalido('Emisor o sujeto inválido');
        }

        return new Solicitante((string) $claims->sub, $this->rolesDe($claims));
    }

    private function decodificar(string $token): object
    {
        try {
            return JWT::decode($token, ($this->claves)(false));
        } catch (\UnexpectedValueException $e) {
            // Una clave nueva (rotación en Keycloak) no está en la caché: se refresca una vez.
            if (str_contains($e->getMessage(), '"kid"')) {
                try {
                    return JWT::decode($token, ($this->claves)(true));
                } catch (\UnexpectedValueException|\DomainException|\InvalidArgumentException $segundo) {
                    throw new TokenInvalido($segundo->getMessage(), 0, $segundo);
                }
            }
            throw new TokenInvalido($e->getMessage(), 0, $e);
        } catch (\DomainException|\InvalidArgumentException $e) {
            throw new TokenInvalido($e->getMessage(), 0, $e);
        }
    }

    /** @return list<Rol> */
    private function rolesDe(object $claims): array
    {
        $nombres = $claims->realm_access->roles ?? [];
        if (! is_array($nombres)) {
            return [];
        }
        $roles = [];
        foreach ($nombres as $nombre) {
            $rol = is_string($nombre) ? Rol::tryFrom($nombre) : null;
            if ($rol !== null) {
                $roles[] = $rol;
            }
        }

        return $roles;
    }
}
