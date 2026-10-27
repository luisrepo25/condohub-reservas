<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Forma de `POST /api/reservas/{id}/finalizar` (contrato FinalizarReserva). `conDanios` debe ser true o false. */
final class FinalizarReservaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Solo ADMIN: lo exige la ruta.
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'conDanios' => ['required', 'boolean:strict'],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    public function conDanios(): bool
    {
        return $this->validated('conDanios') === true;
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }
}
