<?php

// Objetos de valor (Dinero, IdempotencyKey, AreaComun) y disponibilidad del área (R2).

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\Model\AreaComun;
use CondoHub\Reservas\Domain\Service\VerificadorDisponibilidad;
use CondoHub\Reservas\Domain\ValueObject\Dinero;
use CondoHub\Reservas\Domain\ValueObject\IdempotencyKey;
use CondoHub\Reservas\Domain\ValueObject\TipoArea;
use Tests\Support\Escenario;

it('R2 un bloque que se solapa con una reserva activa del área se rechaza', function () {
    $ocupados = [Escenario::bloque('2026-10-30 14:00', '2026-10-30 18:00')];

    (new VerificadorDisponibilidad)->exigirLibre(Escenario::bloque('2026-10-30 17:00', '2026-10-30 19:00'), $ocupados);
})->throws(ReglaNegocioViolada::class, 'ya está reservada');

it('R2 bloques contiguos no se solapan', function () {
    $ocupados = [Escenario::bloque('2026-10-30 14:00', '2026-10-30 18:00')];
    $verificador = new VerificadorDisponibilidad;

    $verificador->exigirLibre(Escenario::bloque('2026-10-30 18:00', '2026-10-30 20:00'), $ocupados);
    $verificador->exigirLibre(Escenario::bloque('2026-10-30 10:00', '2026-10-30 14:00'), $ocupados);

    expect(true)->toBeTrue();
});

it('R5 solo el salón de eventos puede tener garantía', function () {
    new AreaComun(
        'a0000000-0000-4000-8000-000000000002', 'PISCINA', 'Piscina', TipoArea::Piscina, 20, '08:00', '23:00', 2,
        Dinero::cero(), Dinero::de('100.00'), true,
    );
})->throws(ReglaNegocioViolada::class, 'Solo el salón');

it('F10 Dinero guarda 2 decimales exactos sin usar float', function () {
    expect((string) Dinero::de('500'))->toBe('500.00')
        ->and((string) Dinero::de('0.5'))->toBe('0.50')
        ->and((string) Dinero::de('1234.07'))->toBe('1234.07')
        ->and(Dinero::de('10.10')->equals(Dinero::de('10.1')))->toBeTrue()
        ->and(Dinero::cero()->esCero())->toBeTrue();
});

it('F10 Dinero rechaza negativos, más de 2 decimales y texto', function (string $valor) {
    Dinero::de($valor);
})->with(['-1.00', '1.234', 'abc', '1,50', ''])->throws(InvalidArgumentException::class);

it('O1 la Idempotency-Key debe ser un UUID v4', function () {
    expect(IdempotencyKey::de('11111111-1111-4111-8111-111111111111')->valor)->toBe('11111111-1111-4111-8111-111111111111');
    expect(fn () => IdempotencyKey::de('no-es-uuid'))->toThrow(ReglaNegocioViolada::class);
    // UUID v1 (basado en tiempo), no v4
    expect(fn () => IdempotencyKey::de('a8098c1a-f86e-11da-bd1a-00112444be1e'))->toThrow(ReglaNegocioViolada::class);
});
