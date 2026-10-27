<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Forma de `POST /api/reservas/{id}/cancelar` (contrato CancelarReserva + header Idempotency-Key). */
final class CancelarReservaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El rol lo exige la ruta; F1 lo aplica el caso de uso.
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotencyKey' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'idempotencyKey' => ['required', 'string'],
            'version' => ['required', 'integer', 'min:0'],
            'motivo' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['idempotencyKey.required' => 'Falta el header Idempotency-Key (UUID v4 generado en el dispositivo)'];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotencyKey');
    }

    public function version(): int
    {
        return (int) $this->validated('version');
    }

    public function motivo(): ?string
    {
        $motivo = $this->validated('motivo');

        return is_string($motivo) ? $motivo : null;
    }
}
