<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\ValueObject;

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;

/** O1: clave UUID v4 generada en el dispositivo; repetirla devuelve la misma respuesta (O3). */
final readonly class IdempotencyKey
{
    private function __construct(public string $valor) {}

    public static function de(string $valor): self
    {
        $valor = strtolower(trim($valor));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $valor) !== 1) {
            throw new ReglaNegocioViolada('O1', 'La Idempotency-Key debe ser un UUID v4');
        }

        return new self($valor);
    }

    public function equals(self $otra): bool
    {
        return $this->valor === $otra->valor;
    }
}
