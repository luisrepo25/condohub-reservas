<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Domain\Exception;

/**
 * Un dato de entrada no tiene la forma válida (monto, nombre de invitado, documento repetido...). No es una regla de
 * negocio violada sino una solicitud mal formada: HTTP 400. Es propia del dominio para no confundirla con un
 * InvalidArgumentException de la infraestructura (que es un fallo del sistema, 500).
 */
final class DatoInvalido extends \InvalidArgumentException {}
