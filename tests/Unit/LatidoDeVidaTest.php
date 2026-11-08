<?php

// S4: el publicador del outbox no puede quedarse colgado sin que nadie lo note (el 07/11/2026 dejó 1 h sin publicar y la saga venció).

use CondoHub\Reservas\Infrastructure\Messaging\LatidoDeVida;

function archivoDeLatido(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'latido-prueba-'.bin2hex(random_bytes(4));
}

it('S4 un latido reciente no vence', function () {
    $archivo = archivoDeLatido();
    $latido = new LatidoDeVida($archivo, 120);
    $latido->latir(1000);

    expect($latido->vencido(1100))->toBeFalse();
    @unlink($archivo);
});

it('S4 justo en el límite todavía no vence', function () {
    $archivo = archivoDeLatido();
    $latido = new LatidoDeVida($archivo, 120);
    $latido->latir(1000);

    expect($latido->vencido(1120))->toBeFalse();
    @unlink($archivo);
});

it('S4 un latido más viejo que el máximo vence', function () {
    $archivo = archivoDeLatido();
    $latido = new LatidoDeVida($archivo, 120);
    $latido->latir(1000);

    expect($latido->vencido(1121))->toBeTrue();
    @unlink($archivo);
});

it('S4 sin ningún latido registrado cuenta como vencido', function () {
    expect((new LatidoDeVida(archivoDeLatido(), 120))->vencido(5000))->toBeTrue();
});

it('S4 un latido nuevo renueva la vigencia', function () {
    $archivo = archivoDeLatido();
    $latido = new LatidoDeVida($archivo, 120);
    $latido->latir(1000);
    $latido->latir(1200);

    expect($latido->vencido(1300))->toBeFalse();
    @unlink($archivo);
});

it('S4 el guion vigila el proceso indicado, compara con el máximo y lo mata con SIGKILL', function () {
    $guion = (new LatidoDeVida('/tmp/latido', 90))->guion(4321);

    expect($guion)->toContain('kill -0 4321')->toContain('-gt 90')->toContain('kill -9 4321')->toContain('/tmp/latido');
});

it('S4 nunca vigila al proceso 1: Linux ignoraría el SIGKILL', function () {
    expect((new LatidoDeVida(archivoDeLatido(), 90))->vigilar(1))->toBeFalse();
});

it('S4 con el máximo en 0 la vigilancia queda desactivada', function () {
    expect((new LatidoDeVida(archivoDeLatido(), 0))->vigilar(4321))->toBeFalse();
});

it('S4 el vigilante mata a un proceso que dejó de latir', function () {
    if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v sh')) === '') {
        $this->markTestSkipped('Necesita un sistema con sh (Linux).');
    }
    $archivo = archivoDeLatido();
    // Un proceso «colgado»: duerme 60 s y nunca late.
    $colgado = proc_open(['sleep', '60'], [], $t);
    $pid = (int) proc_get_status($colgado)['pid'];
    file_put_contents($archivo, '1'); // latido de hace décadas
    $latido = new LatidoDeVida($archivo, 10);
    proc_open(['sh', '-c', str_replace('sleep 5', 'sleep 1', $latido->guion($pid))], [], $t2);

    $fin = microtime(true) + 8;
    while (microtime(true) < $fin && (proc_get_status($colgado)['running'] ?? false)) {
        usleep(200_000);
    }

    expect(proc_get_status($colgado)['running'])->toBeFalse();
    @unlink($archivo);
});
