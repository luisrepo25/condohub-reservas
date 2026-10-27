<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Persistence;

use CondoHub\Reservas\Domain\Model\AreaComun;
use CondoHub\Reservas\Domain\Port\AreaComunRepository;
use CondoHub\Reservas\Domain\ValueObject\Dinero;
use CondoHub\Reservas\Domain\ValueObject\TipoArea;
use Illuminate\Database\ConnectionInterface;

/** AreaComunRepository sobre la tabla `area_comun` de PostgreSQL (SQL directo, sin Eloquent). */
final class PgAreaComunRepository implements AreaComunRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    private const COLUMNAS = "id, codigo, nombre, tipo, aforo, to_char(hora_apertura, 'HH24:MI') AS hora_apertura,
        to_char(hora_cierre, 'HH24:MI') AS hora_cierre, bloque_minimo_horas, costo_uso::text AS costo_uso,
        garantia::text AS garantia, activa";

    public function activas(): array
    {
        $filas = $this->db->select('SELECT '.self::COLUMNAS.' FROM area_comun WHERE activa ORDER BY codigo');

        return array_values(array_map(self::aArea(...), $filas));
    }

    public function porId(string $id): ?AreaComun
    {
        $fila = $this->db->selectOne('SELECT '.self::COLUMNAS.' FROM area_comun WHERE id = ?::uuid', [$id]);

        return $fila === null ? null : self::aArea($fila);
    }

    private static function aArea(object $fila): AreaComun
    {
        /** @var object{id: string, codigo: string, nombre: string, tipo: string, aforo: int, hora_apertura: string, hora_cierre: string, bloque_minimo_horas: int, costo_uso: string, garantia: string, activa: bool} $fila */
        return new AreaComun(
            $fila->id,
            $fila->codigo,
            $fila->nombre,
            TipoArea::from($fila->tipo),
            (int) $fila->aforo,
            $fila->hora_apertura,
            $fila->hora_cierre,
            (int) $fila->bloque_minimo_horas,
            Dinero::de($fila->costo_uso),
            Dinero::de($fila->garantia),
            (bool) $fila->activa,
        );
    }
}
