<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\ValueObject;

/** Invitado de una reserva; Acceso le emite un pase QR en el paso 3 de la saga. */
final readonly class Invitado
{
    public string $nombre;

    public ?string $documento;

    public function __construct(string $nombre, ?string $documento = null)
    {
        $nombre = trim($nombre);
        $documento = $documento === null || trim($documento) === '' ? null : trim($documento);
        if ($nombre === '' || mb_strlen($nombre) > 120) {
            throw new \InvalidArgumentException('El nombre del invitado es obligatorio y admite hasta 120 caracteres');
        }
        if ($documento !== null && mb_strlen($documento) > 30) {
            throw new \InvalidArgumentException('El documento del invitado admite hasta 30 caracteres');
        }
        $this->nombre = $nombre;
        $this->documento = $documento;
    }
}
