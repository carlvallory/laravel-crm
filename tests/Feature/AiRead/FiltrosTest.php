<?php

use Carbon\Carbon;
use CarlVallory\KrayinAiRead\Http\Controllers\IaController;
use CarlVallory\KrayinAiRead\Http\Filters\FilterSet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Tests\Feature\AiRead\AiReadFixtures as F;

uses(DatabaseTransactions::class);

/** Controller de prueba: devuelve los filtros, o ejecuta lo que se le pase. */
function controllerDePrueba(?Closure $fn = null): object
{
    return new class($fn) extends IaController {
        public function __construct(private ?Closure $fn) {}

        public function run(Request $r)
        {
            return $this->responder($r, ['clase' => ['nullable', Rule::in(['entrada', 'merch'])]], $this->fn ?? fn () => [[], []]);
        }
    };
}

beforeEach(function () {
    F::useDefaultConnection();
    $c = controllerDePrueba();
    F::rutaDePrueba('_filtros', fn (Request $r) => $c->run($r));
    $this->token = F::token();
});

function filtros(string $query): \Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->getJson('/api/ia/v1/_filtros'.$query);
}

it('aplica valores por defecto y responde con la forma acordada', function () {
    $r = filtros('')->assertOk();

    expect($r->json())->toHaveKeys(['datos', 'filtros_aplicados', 'avisos', 'generado_en'])
        ->and($r->json('filtros_aplicados.hasta'))->toBe(Carbon::today('America/Asuncion')->toDateString())
        ->and($r->json('filtros_aplicados.desde'))->toBe(Carbon::today('America/Asuncion')->subDays(29)->toDateString())
        ->and($r->json('filtros_aplicados.agrupar'))->toBe('mes')
        ->and($r->json('filtros_aplicados.limite'))->toBe(20)
        ->and($r->json('generado_en'))->toEndWith('-03:00');
});

it('calcula hoy en la zona del paquete, no en la de la app', function () {
    // 20:00 en Asunción (-03:00) = 04:30 del día siguiente en Kolkata (app.timezone del fork).
    Carbon::setTestNow(Carbon::parse('2031-03-10 20:00:00', 'America/Asuncion'));

    try {
        $this->token = F::token(); // el de beforeEach ya venció en 2031

        filtros('')->assertOk()
            ->assertJsonPath('filtros_aplicados.hasta', '2031-03-10')
            ->assertJsonPath('filtros_aplicados.desde', '2031-02-09');
    } finally {
        Carbon::setTestNow();
    }
});

it('explica los filtros inválidos en español', function (string $query, string $detalle) {
    filtros($query)->assertStatus(422)->assertJsonPath('detalle', $detalle);
})->with([
    'limite no numérico' => ['?limite=abc', 'limite tiene que ser un número entero.'],
    'fecha inexistente'  => ['?desde=2031-02-30', 'desde tiene que ser una fecha con formato AAAA-MM-DD.'],
    'agrupar inválido'   => ['?agrupar=anio', 'agrupar tiene que ser dia, semana o mes.'],
    'extra inválido'     => ['?clase=programa', 'El valor de clase no es válido.'],
]);

it('devuelve los filtros normalizados', function () {
    filtros('?desde=2031-03-01&hasta=2031-03-31&agrupar=dia&sucursal=San%20Cosmos&variacion=en_puerta&tipo=merch&producto=4,7&limite=5&clase=merch')
        ->assertOk()
        ->assertJsonPath('filtros_aplicados', [
            'desde' => '2031-03-01', 'hasta' => '2031-03-31', 'agrupar' => 'dia',
            'sucursal' => 'San Cosmos', 'variacion' => 'en_puerta', 'tipo' => 'merch',
            'producto' => [4, 7], 'limite' => 5, 'clase' => 'merch',
        ]);
});

it('ignora parámetros vacíos', function () {
    filtros('?sucursal=&tipo=')->assertOk()->assertJsonMissingPath('filtros_aplicados.sucursal');
});

it('rechaza filtros inválidos con 422 filtro_invalido', function (string $query) {
    filtros($query)->assertStatus(422)->assertJsonPath('error', 'filtro_invalido');
})->with([
    'parámetro desconocido' => ['?campana=x'],
    'token por query'       => ['?token=abc'],
    'rango > 3 años'        => ['?desde=2027-01-01&hasta=2031-03-31'],
    'desde > hasta'         => ['?desde=2031-04-01&hasta=2031-03-01'],
    'fecha inexistente'     => ['?desde=2031-02-30'],
    'limite > 100'          => ['?limite=101'],
    'limite no numérico'    => ['?limite=abc'],
    'agrupar inválido'      => ['?agrupar=anio'],
    'variación inválida'    => ['?variacion=Cortesía'],
    'producto como array'   => ['?producto[]=1'],
    'producto no numérico'  => ['?producto=abc'],
    'más de 10 productos'   => ['?producto=1,2,3,4,5,6,7,8,9,10,11'],
    'sucursal como array'   => ['?sucursal[]=x'],
    'extra inválido'        => ['?clase=programa'],
]);

it('nombra los parámetros permitidos cuando hay uno desconocido', function () {
    expect(filtros('?campana=x')->json('detalle'))->toContain('campana')->toContain('desde');
});

it('traduce el timeout de la base en 504 consulta_lenta', function () {
    $pdo = new PDOException('Query execution was interrupted (max_statement_time exceeded)');
    $pdo->errorInfo = ['70100', 1969, 'Query execution was interrupted (max_statement_time exceeded)'];
    $lenta = controllerDePrueba(fn () => throw new QueryException('mysql', 'select 1', [], $pdo));
    F::rutaDePrueba('_lenta', fn (Request $r) => $lenta->run($r));

    $this->withToken($this->token)->getJson('/api/ia/v1/_lenta')
        ->assertStatus(504)->assertJsonPath('error', 'consulta_lenta');
});
