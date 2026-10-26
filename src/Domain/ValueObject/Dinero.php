<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\ValueObject;

/**
 * Monto de dinero, nunca negativo y con 2 decimales (F10). Se guarda en centavos enteros: nunca float.
 * Entra y sale como string decimal ("500.00"), igual que en la base (NUMERIC(12,2)) y en el JSON.
 */
final readonly class Dinero
{
    private function __construct(public int $centavos) {}

    public static function de(string $valor): self
    {
        if (preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $valor, $partes) !== 1) {
            throw new \InvalidArgumentException("Dinero inválido: '{$valor}' (string decimal, no negativo, hasta 2 decimales)");
        }

        return new self((int) $partes[1] * 100 + (int) str_pad($partes[2] ?? '0', 2, '0'));
    }

    public static function cero(): self
    {
        return new self(0);
    }

    public function esCero(): bool
    {
        return $this->centavos === 0;
    }

    public function equals(self $otro): bool
    {
        return $this->centavos === $otro->centavos;
    }

    /** String decimal con 2 decimales, el formato del contrato. */
    public function __toString(): string
    {
        return intdiv($this->centavos, 100).'.'.str_pad((string) ($this->centavos % 100), 2, '0', STR_PAD_LEFT);
    }
}
