<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Messaging;

use CondoHub\Reservas\Application\Saga\RespuestaSaga;

/**
 * Lee el sobre de contracts/events (`{eventId, type, sagaId, data}`) de una respuesta de la saga y lo traduce a
 * RespuestaSaga: los ids de los cargos (Gestión) o los códigos de los pases (Acceso).
 */
final class LectorRespuestaSaga
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function desde(string $cuerpo): RespuestaSaga
    {
        $sobre = json_decode($cuerpo, true);
        if (! is_array($sobre) || ! is_array($sobre['data'] ?? null)) {
            throw new MensajeSagaInvalido('El mensaje no es un sobre de evento con data');
        }
        $tipo = $sobre['type'] ?? null;
        if (! in_array($tipo, RespuestaSaga::TIPOS, true)) {
            throw new MensajeSagaInvalido('Tipo de mensaje no esperado en la cola de la saga: '.json_encode($tipo));
        }
        $data = $sobre['data'];
        $sagaId = $sobre['sagaId'] ?? null;

        return new RespuestaSaga(
            self::uuid($sobre['eventId'] ?? null, 'eventId'),
            $tipo,
            $sagaId === null ? null : self::uuid($sagaId, 'sagaId'),
            self::uuid($data['reservaId'] ?? null, 'data.reservaId'),
            self::ids($tipo, $data),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private static function ids(string $tipo, array $data): array
    {
        $lista = match ($tipo) {
            RespuestaSaga::CARGO_APLICADO, RespuestaSaga::CARGO_ANULADO => $data['cargoIds'] ?? null,
            RespuestaSaga::PASE_EMITIDO => array_map(
                static fn (mixed $p): mixed => is_array($p) ? ($p['codigo'] ?? null) : null,
                is_array($data['pases'] ?? null) ? $data['pases'] : [null],
            ),
            default => [],
        };
        if (! is_array($lista)) {
            throw new MensajeSagaInvalido("{$tipo} sin la lista de ids de su contrato");
        }
        $ids = [];
        foreach ($lista as $id) {
            if (! is_string($id) || trim($id) === '') {
                throw new MensajeSagaInvalido("{$tipo} con un id vacío o que no es texto");
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private static function uuid(mixed $valor, string $campo): string
    {
        if (! is_string($valor) || preg_match(self::UUID, $valor) !== 1) {
            throw new MensajeSagaInvalido("El campo {$campo} no es un UUID");
        }

        return strtolower($valor);
    }
}
