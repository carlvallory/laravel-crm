<?php

use CarlVallory\KrayinTicketSales\Services\ErrorDelServicio;
use CarlVallory\KrayinTicketSales\Services\FooEventsServiceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'ticket-sales.service.url'            => 'http://127.0.0.1:8081',
        'ticket-sales.service.token'          => 'un-token',
        // Sin esto cada test de reintento espera 2 segundos de verdad.
        'ticket-sales.service.retry_delay_ms' => 0,
    ]);

    $this->cliente = app(FooEventsServiceClient::class);
});

function respuestaCanonica(): array
{
    return json_decode(
        file_get_contents(base_path('tests/Fixtures/fooevents/respuesta-ejemplo.json')),
        true
    );
}

test('200 devuelve la respuesta canónica ya validada', function () {
    Http::fake(['*' => Http::response(respuestaCanonica(), 200)]);

    $datos = $this->cliente->funcionesDe('2026-08-07');

    expect($datos['fecha'])->toBe('2026-08-07');
    expect($datos['avisos'])->toBe([]);
    expect($datos['funciones'])->toHaveCount(1);
    expect($datos['funciones'][0]['producto_id'])->toBe(192637);
    expect($datos['funciones'][0]['entradas_vendidas'])->toBe(2);
    expect($datos['funciones'][0]['recaudacion_bruta'])->toBe(70000);
});

test('manda el token como Bearer y la fecha como query', function () {
    Http::fake(['*' => Http::response(respuestaCanonica(), 200)]);

    $this->cliente->funcionesDe('2026-08-07');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'http://127.0.0.1:8081/v1/funciones?fecha=2026-08-07'
            && $request->hasHeader('Authorization', 'Bearer un-token');
    });
});

test('503 se reintenta una vez y después se rinde', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => 'origen_no_disponible', 'mensaje' => 'x'], 503)
        ->push(['error' => 'origen_no_disponible', 'mensaje' => 'x'], 503),
    ]);

    $error = null;

    try {
        $this->cliente->funcionesDe('2026-08-07');
    } catch (ErrorDelServicio $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->nivel())->toBe('warning');
    Http::assertSentCount(2);
});

test('un 503 seguido de un 200 devuelve los datos', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => 'origen_no_disponible', 'mensaje' => 'x'], 503)
        ->push(respuestaCanonica(), 200),
    ]);

    $datos = $this->cliente->funcionesDe('2026-08-07');

    expect($datos['funciones'])->toHaveCount(1);
    Http::assertSentCount(2);
});

test('500 se reintenta igual que el 503', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['error' => 'x', 'mensaje' => 'x'], 500)
        ->push(['error' => 'x', 'mensaje' => 'x'], 500),
    ]);

    $error = null;

    try {
        $this->cliente->funcionesDe('2026-08-07');
    } catch (ErrorDelServicio $e) {
        $error = $e;
    }

    // `toThrow(ErrorDelServicio::class)` a secas no alcanza: `respuestaInvalida`
    // también es un ErrorDelServicio, así que un cliente que dejara pasar el 500
    // hasta `validar()` y se ahogara con el cuerpo del error pasaría igual. Lo
    // que hay que fijar es la CLASIFICACIÓN: un 500 es servicio caído (`warning`,
    // ruido de red esperable), no contrato roto (`error`, que exige mirar).
    expect($error)->not->toBeNull();
    expect($error->nivel())->toBe('warning');
    expect($error->getMessage())->toContain('500');
    Http::assertSentCount(2);
});

test('un timeout se reintenta una vez', function () {
    // El contador va acá y no en `Http::assertSentCount`: cuando el stub lanza,
    // Laravel no llega a registrar el par request/response y el assert daría 0.
    $intentos = 0;

    Http::fake(function () use (&$intentos) {
        $intentos++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $error = null;

    try {
        $this->cliente->funcionesDe('2026-08-07');
    } catch (ErrorDelServicio $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->nivel())->toBe('warning');
    expect($intentos)->toBe(2);
});

test('401 no se reintenta y es error, no warning', function () {
    Http::fake(['*' => Http::response(['error' => 'no_autorizado', 'mensaje' => 'x'], 401)]);

    $error = null;

    try {
        $this->cliente->funcionesDe('2026-08-07');
    } catch (ErrorDelServicio $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->nivel())->toBe('error');
    Http::assertSentCount(1);
});

test('422 no se reintenta y es error: es un bug del CRM', function () {
    Http::fake(['*' => Http::response(['error' => 'fecha_invalida', 'mensaje' => 'x'], 422)]);

    $error = null;

    try {
        $this->cliente->funcionesDe('2026-08-07');
    } catch (ErrorDelServicio $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->nivel())->toBe('error');
    Http::assertSentCount(1);
});

test('una respuesta sin la clave funciones se rechaza', function () {
    Http::fake(['*' => Http::response([
        'fecha'       => '2026-08-07',
        'generado_en' => '2026-08-07T17:30:00-03:00',
        'avisos'      => [],
    ], 200)]);

    $error = null;

    try {
        $this->cliente->funcionesDe('2026-08-07');
    } catch (ErrorDelServicio $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->nivel())->toBe('error');
});

test('una respuesta que no es JSON se rechaza', function () {
    Http::fake(['*' => Http::response('<html>502 Bad Gateway</html>', 200)]);

    expect(fn () => $this->cliente->funcionesDe('2026-08-07'))
        ->toThrow(ErrorDelServicio::class);
});

test('una respuesta de otra fecha se rechaza', function () {
    Http::fake(['*' => Http::response(respuestaCanonica(), 200)]);

    // El fixture es del 2026-08-07; se pide el 08.
    expect(fn () => $this->cliente->funcionesDe('2026-08-08'))
        ->toThrow(ErrorDelServicio::class);
});

test('una función a la que le falta un campo se rechaza', function () {
    $cuerpo = respuestaCanonica();
    unset($cuerpo['funciones'][0]['recaudacion_neta']);

    Http::fake(['*' => Http::response($cuerpo, 200)]);

    expect(fn () => $this->cliente->funcionesDe('2026-08-07'))
        ->toThrow(ErrorDelServicio::class);
});

test('cupos_habilitados y hora en null son válidos', function () {
    $cuerpo = respuestaCanonica();
    $cuerpo['funciones'][0]['cupos_habilitados'] = null;
    $cuerpo['funciones'][0]['hora']              = null;

    Http::fake(['*' => Http::response($cuerpo, 200)]);

    $datos = $this->cliente->funcionesDe('2026-08-07');

    expect($datos['funciones'][0]['cupos_habilitados'])->toBeNull();
    expect($datos['funciones'][0]['hora'])->toBeNull();
});

test('un aviso con un tipo desconocido no rompe nada', function () {
    $cuerpo           = respuestaCanonica();
    $cuerpo['avisos'] = [
        ['tipo' => 'linea_faltante', 'detalle' => 'conocido'],
        ['tipo' => 'un_codigo_del_futuro', 'detalle' => 'todavía no existe'],
    ];

    Http::fake(['*' => Http::response($cuerpo, 200)]);

    $datos = $this->cliente->funcionesDe('2026-08-07');

    expect($datos['avisos'])->toHaveCount(2);
    expect($datos['avisos'][1]['tipo'])->toBe('un_codigo_del_futuro');
});

test('un aviso sin la clave detalle sí se rechaza', function () {
    $cuerpo           = respuestaCanonica();
    $cuerpo['avisos'] = [['tipo' => 'json_ilegible']];

    Http::fake(['*' => Http::response($cuerpo, 200)]);

    expect(fn () => $this->cliente->funcionesDe('2026-08-07'))
        ->toThrow(ErrorDelServicio::class);
});

test('un día sin funciones es una respuesta válida, no un error', function () {
    Http::fake(['*' => Http::response([
        'fecha'       => '2026-08-07',
        'generado_en' => '2026-08-07T17:30:00-03:00',
        'avisos'      => [],
        'funciones'   => [],
    ], 200)]);

    $datos = $this->cliente->funcionesDe('2026-08-07');

    expect($datos['funciones'])->toBe([]);
});
