<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\Saga;

/**
 * Respuesta de un paso de la saga, ya leída del sobre de contracts/events: `finanzas.CargoAplicado`,
 * `finanzas.CargoAnulado`, `acceso.PaseEmitido` o `acceso.PaseRevocado`.
 */
final readonly class RespuestaSaga
{
    public const CARGO_APLICADO = 'finanzas.CargoAplicado';

    public const CARGO_ANULADO = 'finanzas.CargoAnulado';

    public const PASE_EMITIDO = 'acceso.PaseEmitido';

    public const PASE_REVOCADO = 'acceso.PaseRevocado';

    public const TIPOS = [self::CARGO_APLICADO, self::CARGO_ANULADO, self::PASE_EMITIDO, self::PASE_REVOCADO];

    /**
     * @param  list<string>  $ids  cargoIds (Gestión) o códigos de los pases (Acceso)
     */
    public function __construct(
        public string $eventId,
        public string $tipo,
        public ?string $sagaId,
        public string $reservaId,
        public array $ids = [],
    ) {}
}
