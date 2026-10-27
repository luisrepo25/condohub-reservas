<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Application\UseCase;

/** Resultado de una vuelta del publicador: cuántos salieron y, si uno falló, cuál y por qué. */
final readonly class ResultadoPublicacion
{
    public function __construct(
        public int $publicados,
        public ?string $fallido,
        public ?string $error,
    ) {}
}
