<?php

declare(strict_types=1);

namespace Tests\Support;

use CondoHub\Reservas\Domain\Model\AreaComun;
use CondoHub\Reservas\Domain\Model\Reserva;
use CondoHub\Reservas\Domain\ValueObject\BloqueHorario;
use CondoHub\Reservas\Domain\ValueObject\Dinero;
use CondoHub\Reservas\Domain\ValueObject\EstadoReserva;
use CondoHub\Reservas\Domain\ValueObject\IdempotencyKey;
use CondoHub\Reservas\Domain\ValueObject\Invitado;
use CondoHub\Reservas\Domain\ValueObject\ParametrosReserva;
use CondoHub\Reservas\Domain\ValueObject\TipoArea;

/**
 * Datos de prueba del dominio de Reservas: las áreas y parámetros de 001_esquema_reservas.sql, el condominio en La Paz
 * (UTC-4) y "ahora" = lunes 26/10/2026 12:00 hora local.
 */
final class Escenario
{
    public const UNIDAD = '00000000-0000-4000-8000-0000000000a1';

    public static function zona(): \DateTimeZone
    {
        return new \DateTimeZone('America/La_Paz');
    }

    public static function ahora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-26 12:00', self::zona());
    }

    /** Fecha y hora local del condominio, ej. "2026-10-30 14:00". */
    public static function local(string $fechaHora): \DateTimeImmutable
    {
        return new \DateTimeImmutable($fechaHora, self::zona());
    }

    public static function bloque(string $inicioLocal, string $finLocal): BloqueHorario
    {
        return new BloqueHorario(self::local($inicioLocal), self::local($finLocal));
    }

    public static function parametros(): ParametrosReserva
    {
        return new ParametrosReserva(48, 60, 2, 48, self::zona());
    }

    public static function salon(bool $activa = true): AreaComun
    {
        return new AreaComun(
            'a0000000-0000-4000-8000-000000000001', 'SALON', 'Salón de eventos', TipoArea::SalonEventos, 80,
            '08:00', '23:00', 2, Dinero::de('500.00'), Dinero::de('300.00'), $activa,
        );
    }

    public static function churrasquera(): AreaComun
    {
        return new AreaComun(
            'a0000000-0000-4000-8000-000000000003', 'CHURR-1', 'Churrasquera 1', TipoArea::Churrasquera, 15,
            '08:00', '23:00', 2, Dinero::de('50.00'), Dinero::cero(), true,
        );
    }

    /** @param list<Invitado> $invitados */
    public static function solicitar(
        ?AreaComun $area = null,
        ?BloqueHorario $bloque = null,
        array $invitados = [],
        int $activas = 0,
    ): Reserva {
        return Reserva::solicitar(
            'b0000000-0000-4000-8000-000000000001',
            $area ?? self::salon(),
            self::UNIDAD,
            'sub-residente',
            $bloque ?? self::bloque('2026-10-30 14:00', '2026-10-30 18:00'),
            $invitados,
            IdempotencyKey::de('11111111-1111-4111-8111-111111111111'),
            self::ahora(),
            $activas,
            self::parametros(),
        );
    }

    /** Reserva ya CONFIRMADA por la saga (versión 1), del salón el viernes 30/10 de 14:00 a 18:00. */
    public static function confirmada(?AreaComun $area = null): Reserva
    {
        $area ??= self::salon();

        return Reserva::reconstituir(
            'b0000000-0000-4000-8000-000000000001', $area->id, self::UNIDAD, 'sub-residente',
            self::bloque('2026-10-30 14:00', '2026-10-30 18:00'), [], $area->costoUso, $area->garantia,
            IdempotencyKey::de('11111111-1111-4111-8111-111111111111'), EstadoReserva::Confirmada, null, null, 1,
        );
    }
}
