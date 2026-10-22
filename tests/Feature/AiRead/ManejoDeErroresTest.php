<?php

use CarlVallory\KrayinAiRead\Http\Controllers\IaController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\Feature\AiRead\AiReadFixtures as F;

uses(DatabaseTransactions::class);

/**
 * Un error que el paquete no traduce (GRANT faltante, tabla inexistente, TypeError, ruta mal
 * escrita) no puede llegarle a la IA como la página HTML del admin con HTTP 200: tiene que
 * salir JSON con el status real, aunque el cliente no mande Accept: application/json.
 */
function controllerQueFalla(Closure $fn): object
{
    return new class($fn) extends IaController {
        public function __construct(private Closure $fn) {}

        public function run(Request $r)
        {
            return $this->responder($r, [], $this->fn);
        }
    };
}

beforeEach(function () {
    F::useDefaultConnection();
    $this->token = F::token();

    $excepcion = controllerQueFalla(fn () => throw new RuntimeException('falla inesperada'));
    F::rutaDePrueba('_falla', fn (Request $r) => $excepcion->run($r));

    $tipo = controllerQueFalla(fn () => strlen([])); // TypeError, no Exception
    F::rutaDePrueba('_falla_tipo', fn (Request $r) => $tipo->run($r));
});

/** Sin Accept: como mandaría un cliente que no leyó la guía. */
function sinAccept(string $metodo, string $uri): \Illuminate\Testing\TestResponse
{
    $t = test()->withToken(test()->token);

    return $metodo === 'POST' ? $t->post($uri, [], ['Accept' => '*/*']) : $t->get($uri, ['Accept' => '*/*']);
}

function esErrorJson(\Illuminate\Testing\TestResponse $r, int $status): void
{
    expect($r->getStatusCode())->toBe($status)
        ->and($r->headers->get('Content-Type'))->toContain('application/json')
        ->and($r->getContent())->not->toContain('<html')
        ->and(json_decode($r->getContent(), true))->toBeArray()->toHaveKey('message');
}

it('un error inesperado sale como JSON 500, no como HTML 200', function (bool $debug, string $ruta) {
    config(['app.debug' => $debug]);

    esErrorJson(sinAccept('GET', "/api/ia/v1/{$ruta}"), 500);
})->with([
    'producción (debug apagado)' => [false],
    'desarrollo (debug prendido)' => [true],
])->with(['_falla', '_falla_tipo']);

it('en producción el error no expone el detalle interno', function () {
    config(['app.debug' => false]);

    expect(sinAccept('GET', '/api/ia/v1/_falla')->getContent())->not->toContain('falla inesperada');
});

it('una ruta mal escrita bajo api/ia sale como JSON 404', function (bool $debug) {
    config(['app.debug' => $debug]);

    esErrorJson(sinAccept('GET', '/api/ia/v1/ventas/resumenn'), 404);
})->with([
    'producción (debug apagado)' => [false],
    'desarrollo (debug prendido)' => [true],
]);

it('un verbo no GET sale como JSON con status de error', function () {
    config(['app.debug' => false]);

    $r = sinAccept('POST', '/api/ia/v1/ventas/resumen');

    // El Handler del admin convierte el 405 en 500 con debug apagado: lo que importa es que no sea 200 ni HTML.
    expect($r->getStatusCode())->toBeGreaterThanOrEqual(400);
    esErrorJson($r, $r->getStatusCode());
});

it('el log de accesos registra el 500', function () {
    $path = storage_path('framework/testing/ia-accesos-errores-test.log');
    @mkdir(dirname($path), 0777, true);
    @unlink($path);
    config(['logging.channels.ia_accesos' => ['driver' => 'single', 'path' => $path, 'level' => 'info']]);
    \Illuminate\Support\Facades\Log::forgetChannel('ia_accesos');

    sinAccept('GET', '/api/ia/v1/_falla')->assertStatus(500);

    expect(file_get_contents($path))->toContain('"ruta":"api/ia/v1/_falla"')->toContain('"http":500');
    @unlink($path);
});

it('no cambia las respuestas fuera de api/ia', function () {
    $r = $this->call('GET', '/admin/login', [], [], [], ['HTTP_ACCEPT' => 'text/html']);

    expect($r->headers->get('Content-Type'))->toContain('text/html');
});
