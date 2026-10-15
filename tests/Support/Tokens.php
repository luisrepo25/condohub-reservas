<?php

declare(strict_types=1);

namespace Tests\Support;

use CondoHub\Reservas\Infrastructure\Security\KeycloakJwtVerificador;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use OpenSSLAsymmetricKey;

/** Firma tokens como lo haría Keycloak (RS256, `realm_access.roles`, `iss`, `exp`, `kid`). */
final class Tokens
{
    public const ISSUER = 'http://localhost:8180/realms/condohub';

    private static ?OpenSSLAsymmetricKey $propia = null;

    private static ?OpenSSLAsymmetricKey $ajena = null;

    /**
     * En Windows (XAMPP) PHP no encuentra `openssl.cnf` y no puede generar claves: se busca junto al binario de PHP.
     *
     * @return array<string, string>
     */
    private static function opcionesOpenssl(): array
    {
        if (getenv('OPENSSL_CONF') !== false) {
            return [];
        }
        foreach ([dirname(PHP_BINARY).'/extras/ssl/openssl.cnf', '/etc/ssl/openssl.cnf'] as $candidato) {
            if (is_file($candidato)) {
                return ['config' => $candidato];
            }
        }

        return [];
    }

    private static function nueva(): OpenSSLAsymmetricKey
    {
        $clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, ...self::opcionesOpenssl()]);
        assert($clave !== false, 'No se pudo generar la clave RSA de prueba (revisa openssl.cnf)');

        return $clave;
    }

    private static function privada(bool $ajena): OpenSSLAsymmetricKey
    {
        return $ajena ? (self::$ajena ??= self::nueva()) : (self::$propia ??= self::nueva());
    }

    private static function publicaPem(): string
    {
        $detalle = openssl_pkey_get_details(self::privada(false));
        assert(is_array($detalle));

        return $detalle['key'];
    }

    public static function verificador(): KeycloakJwtVerificador
    {
        $pem = self::publicaPem();

        return new KeycloakJwtVerificador(self::ISSUER, static fn (bool $refrescar): array => ['k1' => new Key($pem, 'RS256')]);
    }

    /**
     * @param  list<string>  $roles
     * @param  array{sub?: string, issuer?: string, expiraEn?: int, claveAjena?: bool}  $opciones
     */
    public static function crear(array $roles, array $opciones = []): string
    {
        $ahora = time();
        $claims = [
            'sub' => $opciones['sub'] ?? '00000000-0000-0000-0000-00000000a001',
            'iss' => $opciones['issuer'] ?? self::ISSUER,
            'iat' => $ahora,
            'exp' => $ahora + ($opciones['expiraEn'] ?? 900),
            'realm_access' => ['roles' => [...$roles, 'offline_access']],
        ];
        $privada = self::privada($opciones['claveAjena'] ?? false);
        openssl_pkey_export($privada, $pem, null, self::opcionesOpenssl());

        return JWT::encode($claims, $pem, 'RS256', 'k1');
    }
}
