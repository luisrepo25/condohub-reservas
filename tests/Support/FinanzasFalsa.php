<?php

declare(strict_types=1);

namespace Tests\Support;

use CondoHub\Reservas\Application\Port\FinanzasGateway;

/** Gestión simulada: responde lo configurado (vencidas, unidades del solicitante) o lanza el error indicado. */
final class FinanzasFalsa implements FinanzasGateway
{
    /** @var list<string> */
    public array $consultadas = [];

    /** @param list<string> $unidades */
    public function __construct(public int $vencidas = 0, public ?\Throwable $error = null, public array $unidades = []) {}

    public function expensasVencidas(string $unidadId): int
    {
        $this->consultadas[] = $unidadId;
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->vencidas;
    }

    public function unidadesDelSolicitante(): array
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->unidades;
    }
}
