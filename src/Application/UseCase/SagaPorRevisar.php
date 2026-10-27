<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\UseCase;

/** Vista de lectura de una saga atascada (S3), con la forma `Saga` del contrato. */
final readonly class SagaPorRevisar
{
    public function __construct(
        public string $id,
        public string $reservaId,
        public string $estado,
        public int $pasoActual,
        public ?string $ultimoError,
    ) {}
}
