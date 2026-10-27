<?php

declare(strict_types=1);

namespace Tests\Support;

use CondoHub\Reservas\Application\Port\FinanzasGateway;

/** Gestión simulada: responde las expensas vencidas configuradas o lanza el error indicado (F1, caída...). */
final class FinanzasFalsa implements FinanzasGateway
{
    /** @var list<string> */
    public array $consultadas = [];

    public function __construct(public int $vencidas = 0, public ?\Throwable $error = null) {}

    public function expensasVencidas(string $unidadId): int
    {
        $this->consultadas[] = $unidadId;
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->vencidas;
    }
}
