<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Http\Requests;

use CondoHub\Reservas\Domain\ValueObject\EstadoReserva;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros de `GET /api/reservas` (contrato: unidadId y estado, ambos opcionales). */
final class ListarReservasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El rol lo exige la ruta; F1 lo aplica el caso de uso.
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'unidadId' => ['sometimes', 'uuid'],
            'estado' => ['sometimes', Rule::enum(EstadoReserva::class)],
        ];
    }

    public function unidadId(): ?string
    {
        $unidad = $this->validated('unidadId');

        return is_string($unidad) ? strtolower($unidad) : null;
    }

    public function estado(): ?EstadoReserva
    {
        $estado = $this->validated('estado');

        return is_string($estado) ? EstadoReserva::from($estado) : null;
    }
}
