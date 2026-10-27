<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Reloj;

use CondoHub\Reservas\Application\Port\Reloj;

final class RelojSistema implements Reloj
{
    public function ahora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
