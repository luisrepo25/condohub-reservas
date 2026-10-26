<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Model;

use CondoHub\Reservas\Domain\Exception\ReglaNegocioViolada;
use CondoHub\Reservas\Domain\Exception\VersionDesactualizada;
use CondoHub\Reservas\Domain\ValueObject\AlcanceAnulacion;
use CondoHub\Reservas\Domain\ValueObject\BloqueHorario;
use CondoHub\Reservas\Domain\ValueObject\Dinero;
use CondoHub\Reservas\Domain\ValueObject\EstadoReserva;
use CondoHub\Reservas\Domain\ValueObject\IdempotencyKey;
use CondoHub\Reservas\Domain\ValueObject\Invitado;
use CondoHub\Reservas\Domain\ValueObject\ParametrosReserva;

/**
 * Agregado Reserva (R1–R8, O5). Nace SOLICITADA con el horario bloqueado; la saga la lleva a CONFIRMADA o FALLIDA.
 * No hay setters: cada cambio de estado es un método con intención que valida R8.
 * La unidad es del contexto Finanzas: se referencia solo por id; la morosidad (R4) la decide Gestión antes de crearla.
 */
final class Reserva
{
    public const MOTIVO_LARGO_MAXIMO = 300;

    /** @param list<Invitado> $invitados */
    private function __construct(
        public readonly string $id,
        public readonly string $areaId,
        public readonly string $unidadId,
        public readonly string $solicitadaPor,
        public readonly BloqueHorario $bloque,
        public readonly array $invitados,
        public readonly Dinero $costoUso,
        public readonly Dinero $garantia,
        public readonly IdempotencyKey $idempotencyKey,
        private EstadoReserva $estado,
        private ?bool $conDanios,
        private ?string $motivo,
        private int $version,
    ) {}

    /**
     * Solicita una reserva: valida R1, R2, R3 y R5 con los datos del dominio. El solapamiento con otras reservas lo
     * revisa VerificadorDisponibilidad y, ante concurrencia, la restricción EXCLUDE de la base (R2).
     *
     * @param  list<Invitado>  $invitados
     * @param  int  $activasDeLaUnidad  reservas SOLICITADA o CONFIRMADA que ya tiene la unidad (R3)
     */
    public static function solicitar(
        string $id,
        AreaComun $area,
        string $unidadId,
        string $solicitadaPor,
        BloqueHorario $bloque,
        array $invitados,
        IdempotencyKey $idempotencyKey,
        \DateTimeImmutable $ahora,
        int $activasDeLaUnidad,
        ParametrosReserva $parametros,
    ): self {
        $area->exigirActiva();
        $area->exigirBloqueValido($bloque, $parametros->zonaHoraria);
        $area->exigirAforo(count($invitados));
        self::exigirDocumentosUnicos($invitados);
        self::exigirAnticipacion($bloque, $ahora, $parametros);
        if ($activasDeLaUnidad >= $parametros->maxActivasPorUnidad) {
            throw new ReglaNegocioViolada(
                'R3',
                "La unidad ya tiene {$activasDeLaUnidad} reservas activas; el máximo es {$parametros->maxActivasPorUnidad}",
            );
        }

        // R5: el costo de uso y la garantía se copian del área; un cambio de tarifa no afecta reservas ya hechas.
        return new self(
            $id, $area->id, $unidadId, $solicitadaPor, $bloque, $invitados, $area->costoUso, $area->garantia,
            $idempotencyKey, EstadoReserva::Solicitada, null, null, 0,
        );
    }

    /**
     * Reconstruye una reserva guardada (repositorio). No valida reglas de creación: ya se validaron al solicitarla.
     *
     * @param  list<Invitado>  $invitados
     */
    public static function reconstituir(
        string $id,
        string $areaId,
        string $unidadId,
        string $solicitadaPor,
        BloqueHorario $bloque,
        array $invitados,
        Dinero $costoUso,
        Dinero $garantia,
        IdempotencyKey $idempotencyKey,
        EstadoReserva $estado,
        ?bool $conDanios,
        ?string $motivo,
        int $version,
    ): self {
        return new self(
            $id, $areaId, $unidadId, $solicitadaPor, $bloque, $invitados, $costoUso, $garantia, $idempotencyKey,
            $estado, $conDanios, $motivo, $version,
        );
    }

    /** S2: la saga terminó bien (Gestión aplicó los cargos y Acceso emitió los pases). */
    public function confirmar(): void
    {
        $this->pasarA(EstadoReserva::Confirmada);
    }

    /** S2: la saga falló y se compensó; el horario queda libre (la restricción EXCLUDE solo mira reservas activas). */
    public function fallar(string $motivo): void
    {
        $motivo = self::motivoObligatorio($motivo, 'R8');
        $this->pasarA(EstadoReserva::Fallida);
        $this->motivo = $motivo;
    }

    /**
     * R7: cancelación de una reserva CONFIRMADA. Con anticipación suficiente se anulan todos los cargos; si es tardía se
     * mantiene el costo de uso y solo se devuelve la garantía.
     */
    public function cancelar(\DateTimeImmutable $ahora, ?string $motivo, int $versionEsperada, ParametrosReserva $parametros): AlcanceAnulacion
    {
        $this->exigirVersion($versionEsperada);
        $motivo = self::motivoObligatorio(
            $motivo === null || trim($motivo) === '' ? 'Cancelada por el solicitante' : $motivo,
            'R7',
        );
        $this->pasarA(EstadoReserva::Cancelada);
        $this->motivo = $motivo;

        return $this->bloque->minutosHastaElInicio($ahora) >= $parametros->cancelacionSinCostoHoras * 60
            ? AlcanceAnulacion::Todos
            : AlcanceAnulacion::SoloGarantia;
    }

    /**
     * R6: el ADMIN marca la reserva como FINALIZADA. Sin daños se devuelve la garantía (si la hay); con daños, no.
     *
     * @return AlcanceAnulacion|null qué anular en Gestión, o null si no hay nada que devolver
     */
    public function finalizar(bool $conDanios, int $versionEsperada): ?AlcanceAnulacion
    {
        $this->exigirVersion($versionEsperada);
        $this->pasarA(EstadoReserva::Finalizada);
        $this->conDanios = $conDanios;

        return ! $conDanios && ! $this->garantia->esCero() ? AlcanceAnulacion::SoloGarantia : null;
    }

    /** F1: un residente solo opera reservas de una unidad suya; el ADMIN, todas. */
    public function perteneceA(string $unidadId): bool
    {
        return $this->unidadId === $unidadId;
    }

    public function estado(): EstadoReserva
    {
        return $this->estado;
    }

    public function conDanios(): ?bool
    {
        return $this->conDanios;
    }

    public function motivo(): ?string
    {
        return $this->motivo;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function cantidadInvitados(): int
    {
        return count($this->invitados);
    }

    private function pasarA(EstadoReserva $destino): void
    {
        if (! $this->estado->puedePasarA($destino)) {
            throw new ReglaNegocioViolada(
                'R8',
                "Una reserva {$this->estado->value} no puede pasar a {$destino->value}",
            );
        }
        $this->estado = $destino;
        $this->version++;
    }

    /** O5: bloqueo optimista; una versión vieja no modifica la reserva. */
    private function exigirVersion(int $versionEsperada): void
    {
        if ($versionEsperada !== $this->version) {
            throw new VersionDesactualizada($this->version, $versionEsperada);
        }
    }

    private static function exigirAnticipacion(BloqueHorario $bloque, \DateTimeImmutable $ahora, ParametrosReserva $p): void
    {
        $minutos = $bloque->minutosHastaElInicio($ahora);
        if ($minutos < $p->anticipacionMinHoras * 60) {
            throw new ReglaNegocioViolada('R3', "Se reserva con al menos {$p->anticipacionMinHoras} h de anticipación");
        }
        if ($minutos > $p->anticipacionMaxDias * 24 * 60) {
            throw new ReglaNegocioViolada('R3', "Se reserva con hasta {$p->anticipacionMaxDias} días de anticipación");
        }
    }

    /** @param list<Invitado> $invitados */
    private static function exigirDocumentosUnicos(array $invitados): void
    {
        $documentos = array_filter(array_map(static fn (Invitado $i): ?string => $i->documento, $invitados));
        if (count($documentos) !== count(array_unique($documentos))) {
            throw new \InvalidArgumentException('Hay invitados con el mismo documento');
        }
    }

    private static function motivoObligatorio(string $motivo, string $codigo): string
    {
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > self::MOTIVO_LARGO_MAXIMO) {
            throw new ReglaNegocioViolada($codigo, 'El motivo es obligatorio y admite hasta '.self::MOTIVO_LARGO_MAXIMO.' caracteres');
        }

        return $motivo;
    }
}
