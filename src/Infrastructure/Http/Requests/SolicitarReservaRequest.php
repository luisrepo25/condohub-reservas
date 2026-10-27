<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Http\Requests;

use CondoHub\Reservas\Application\UseCase\SolicitudReserva;
use CondoHub\Reservas\Domain\ValueObject\Invitado;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Forma de `POST /api/reservas` (contrato NuevaReserva + header Idempotency-Key). Solo valida la forma; las reglas de
 * negocio (R1–R5) las aplica el dominio. Las fechas deben traer zona (Z u offset): nunca se adivina la hora local.
 */
final class SolicitarReservaRequest extends FormRequest
{
    private const FECHA_CON_ZONA = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';

    public function authorize(): bool
    {
        return true; // El rol lo exige la ruta (jwt:RESIDENTE,ADMIN); F1 lo decide Gestión.
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
            'areaId' => ['required', 'uuid'],
            'unidadId' => ['required', 'uuid'],
            'inicio' => ['required', 'string', 'regex:'.self::FECHA_CON_ZONA],
            'fin' => ['required', 'string', 'regex:'.self::FECHA_CON_ZONA],
            'invitados' => ['sometimes', 'array', 'max:200'],
            'invitados.*' => ['array'],
            'invitados.*.nombre' => ['required', 'string', 'max:120'],
            'invitados.*.documento' => ['nullable', 'string', 'max:30'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'idempotencyKey.required' => 'Falta el header Idempotency-Key (UUID v4 generado en el dispositivo)',
            'inicio.regex' => 'inicio debe ser una fecha ISO-8601 con zona, ej. 2026-11-07T18:00:00Z',
            'fin.regex' => 'fin debe ser una fecha ISO-8601 con zona, ej. 2026-11-07T22:00:00Z',
        ];
    }

    public function solicitud(): SolicitudReserva
    {
        /** @var list<array{nombre: string, documento?: string|null}> $invitados */
        $invitados = $this->validated('invitados', []);

        return new SolicitudReserva(
            (string) $this->validated('idempotencyKey'),
            strtolower((string) $this->validated('areaId')),
            strtolower((string) $this->validated('unidadId')),
            new \DateTimeImmutable((string) $this->validated('inicio')),
            new \DateTimeImmutable((string) $this->validated('fin')),
            array_map(static fn (array $i): Invitado => new Invitado($i['nombre'], $i['documento'] ?? null), $invitados),
        );
    }
}
