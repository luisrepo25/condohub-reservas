<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Model;

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\ValueObject\BloqueHorario;
use CondoHub\Reservas\Domain\ValueObject\Dinero;
use CondoHub\Reservas\Domain\ValueObject\TipoArea;

/**
 * Agregado AreaComun (R1, R2, R5): aforo, horario reservable, bloque mínimo y tarifas. Las áreas y sus valores viven en
 * la base (datos de referencia de R1), no en el código.
 */
final readonly class AreaComun
{
    public function __construct(
        public string $id,
        public string $codigo,
        public string $nombre,
        public TipoArea $tipo,
        public int $aforo,
        /** Hora local "HH:MM" */
        public string $horaApertura,
        /** Hora local "HH:MM" */
        public string $horaCierre,
        public int $bloqueMinimoHoras,
        public Dinero $costoUso,
        public Dinero $garantia,
        public bool $activa,
    ) {
        if ($aforo <= 0 || $bloqueMinimoHoras <= 0) {
            throw new \InvalidArgumentException("Área {$codigo}: el aforo y el bloque mínimo deben ser positivos");
        }
        if (self::minutosDelDia($horaCierre) <= self::minutosDelDia($horaApertura)) {
            throw new \InvalidArgumentException("Área {$codigo}: el cierre debe ser posterior a la apertura");
        }
        if ($tipo !== TipoArea::SalonEventos && ! $garantia->esCero()) {
            throw new ReglaNegocioViolada('R5', 'Solo el salón de eventos tiene garantía');
        }
    }

    /** R1: un área inactiva no se reserva. */
    public function exigirActiva(): void
    {
        if (! $this->activa) {
            throw new ReglaNegocioViolada('R1', "El área {$this->nombre} no está disponible para reservas");
        }
    }

    /**
     * R2: el bloque cae dentro del horario del área en la hora local del condominio, en un solo día, y dura al menos el
     * bloque mínimo.
     */
    public function exigirBloqueValido(BloqueHorario $bloque, \DateTimeZone $zona): void
    {
        $inicio = $bloque->inicio->setTimezone($zona);
        $fin = $bloque->fin->setTimezone($zona);

        if ($bloque->duracionEnMinutos() < $this->bloqueMinimoHoras * 60) {
            throw new ReglaNegocioViolada('R2', "El bloque mínimo de {$this->nombre} es de {$this->bloqueMinimoHoras} h");
        }
        $mismoDia = $inicio->format('Y-m-d') === $fin->format('Y-m-d');
        $minutosInicio = self::minutosDelDia($inicio->format('H:i'));
        $minutosFin = self::minutosDelDia($fin->format('H:i'));
        if (! $mismoDia
            || $minutosInicio < self::minutosDelDia($this->horaApertura)
            || $minutosFin > self::minutosDelDia($this->horaCierre)) {
            throw new ReglaNegocioViolada(
                'R2',
                "{$this->nombre} se reserva de {$this->horaApertura} a {$this->horaCierre}, dentro del mismo día",
            );
        }
    }

    /** R1: el aforo cuenta a todas las personas, quien reserva más sus invitados, y es el límite. */
    public function exigirAforo(int $personas): void
    {
        if ($personas > $this->aforo) {
            throw new ReglaNegocioViolada(
                'R1',
                "{$this->nombre} admite hasta {$this->aforo} personas, contando a quien reserva ({$personas} pedidas)",
            );
        }
    }

    private static function minutosDelDia(string $hora): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::\d{2})?$/', $hora, $partes) !== 1) {
            throw new \InvalidArgumentException("Hora inválida: '{$hora}' (HH:MM)");
        }

        return (int) $partes[1] * 60 + (int) $partes[2];
    }
}
