<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Messaging;

use CondoHub\Reservas\Application\Outbox\MensajePendiente;

/**
 * Sobre común de los eventos de CondoHub (contracts/events/_sobre.schema.json):
 * `{eventId, type, occurredAt, sagaId, version, data}`. `occurredAt` en UTC; `version` es la del esquema de `data`.
 */
final class SobreEvento
{
    public const VERSION = 1;

    public static function json(MensajePendiente $mensaje): string
    {
        $data = json_decode($mensaje->dataJson, false, 512, JSON_THROW_ON_ERROR);
        if (! $data instanceof \stdClass) {
            throw new \UnexpectedValueException("El mensaje {$mensaje->id} del outbox no tiene un objeto como data");
        }

        return json_encode([
            'eventId' => $mensaje->id,
            'type' => $mensaje->tipo,
            'occurredAt' => $mensaje->ocurrido->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'sagaId' => $mensaje->sagaId,
            'version' => self::VERSION,
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
