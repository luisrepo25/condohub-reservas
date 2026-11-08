<?php

declare(strict_types=1);

namespace CondoHub\Reservas\Infrastructure\Messaging;

/**
 * Vigilante del publicador del outbox (S4). El 07/11/2026 el proceso quedó vivo pero mudo: no publicó nada durante más de una hora,
 * sin error ni log (probable conexión rota entre nubes), y las reservas fallaron por «Gestión no aplicó los cargos a tiempo».
 *
 * Cada vuelta del bucle deja una marca de tiempo (`latir`). Un proceso `sh` aparte, que no comparte nada con el bucle, mira esa marca
 * cada pocos segundos y, si pasó más del máximo sin que cambiara, mata al publicador con SIGKILL. Docker lo reinicia solo
 * (`restart: unless-stopped`) y las pendientes salen al volver. Sin dependencias: solo `sh` y la extensión posix de PHP.
 *
 * Linux ignora SIGKILL al proceso 1 del contenedor: el servicio necesita `init: true` en el compose para que PHP no lo sea.
 */
final class LatidoDeVida
{
    /** Cada cuántos segundos mira el vigilante la marca. */
    public const REVISION_SEGUNDOS = 5;

    public function __construct(
        private readonly string $archivo,
        private readonly int $maximoSegundos,
    ) {}

    public static function paraProcesoActual(int $maximoSegundos): self
    {
        return new self(sys_get_temp_dir().DIRECTORY_SEPARATOR.'outbox-latido-'.getmypid(), $maximoSegundos);
    }

    /** Anota que el bucle sigue avanzando. */
    public function latir(?int $ahora = null): void
    {
        file_put_contents($this->archivo, (string) ($ahora ?? time()));
    }

    /** true si el último latido es más viejo que el máximo permitido (justo en el límite todavía vale). */
    public function vencido(int $ahora): bool
    {
        $ultimo = is_file($this->archivo) ? (int) file_get_contents($this->archivo) : 0;

        return ($ahora - $ultimo) > $this->maximoSegundos;
    }

    /** Guion de `sh` que mata a `$pid` si el latido vence; termina solo cuando `$pid` deja de existir. */
    public function guion(int $pid): string
    {
        $archivo = escapeshellarg($this->archivo);
        $revision = self::REVISION_SEGUNDOS;

        return "while kill -0 {$pid} 2>/dev/null; do sleep {$revision}; "
            ."ultimo=\$(cat {$archivo} 2>/dev/null || echo 0); ahora=\$(date +%s); "
            ."if [ \$((ahora - ultimo)) -gt {$this->maximoSegundos} ]; then kill -9 {$pid}; break; fi; done; rm -f {$archivo}";
    }

    /**
     * Arranca el vigilante. false si no se puede (sin `sh`, o el proceso es el 1 del contenedor y Linux ignoraría el SIGKILL):
     * entonces el publicador sigue sin vigilancia, como antes.
     */
    public function vigilar(?int $pid = null): bool
    {
        $pid ??= (int) getmypid();
        if ($pid <= 1 || PHP_OS_FAMILY === 'Windows' || $this->maximoSegundos < 1) {
            return false;
        }
        $this->latir();
        $vacio = ['file', '/dev/null', 'w'];
        $proceso = @proc_open(['sh', '-c', $this->guion($pid)], [0 => ['file', '/dev/null', 'r'], 1 => $vacio, 2 => $vacio], $tuberias);

        return is_resource($proceso);
    }
}
