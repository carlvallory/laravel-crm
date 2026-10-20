<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AiRead\AiReadFixtures as F;

uses(DatabaseTransactions::class);

beforeEach(function () {
    F::useDefaultConnection();
    $this->token = F::token();

    $this->merch = F::product(['name' => 'Remera', 'product_kind' => 'merch']);
    $this->sede = F::product(['name' => 'Entrada San Cosmos', 'product_kind' => 'entrada']);
    $this->especial = F::product(['name' => 'Observación nocturna', 'product_kind' => 'entrada']);
    $this->viejo = F::product(['name' => 'Taza', 'product_kind' => 'merch']);
    $this->nunca = F::product(['name' => 'Gorra', 'product_kind' => 'merch']);
    F::productTags(['San Cosmos' => [$this->sede], 'Tatakualab' => []]);

    // Marzo 2031. El renglón de la remera es cantidad 2 con price = total del renglón (2000).
    $l1 = F::wonLead('2031-03-05 10:00:00', ['lead_value' => 2500, 'branch' => 'San Cosmos']);
    F::leadProduct($l1, $this->merch, 2, 2000);
    F::leadProduct($l1, $this->sede, 1, 500);
    $l2 = F::wonLead('2031-03-06 10:00:00', ['lead_value' => 2500, 'branch' => 'Online']);
    F::leadProduct($l2, $this->especial, 5, 2500);

    // Historia: la remera en marzo de 2030, la taza en junio de 2030.
    $h1 = F::wonLead('2030-03-10 10:00:00', ['lead_value' => 4000, 'branch' => 'Online']);
    F::leadProduct($h1, $this->merch, 4, 4000);
    $h2 = F::wonLead('2030-06-10 10:00:00', ['lead_value' => 1000, 'branch' => 'Online']);
    F::leadProduct($h2, $this->viejo, 1, 1000);
});

function productos(string $ruta, string $query): \Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->getJson("/api/ia/v1/productos/{$ruta}?{$query}");
}

it('rankea por unidades y suma el monto del renglón, no price × cantidad', function () {
    $datos = productos('ranking', 'desde=2031-03-01&hasta=2031-03-31')->assertOk()->json('datos');

    expect(collect($datos)->pluck('producto_id')->all())->toBe([$this->especial, $this->merch, $this->sede])
        ->and(collect($datos)->firstWhere('producto_id', $this->merch)['monto'])->toBe(2000)
        ->and(collect($datos)->firstWhere('producto_id', $this->merch)['unidades'])->toBe(2);
});

it('ordena por monto', function () {
    // Pocas unidades y monto alto: por monto va primero, por unidades va último.
    $caro = F::product(['name' => 'Telescopio', 'product_kind' => 'merch']);
    $l = F::wonLead('2031-03-07 10:00:00', ['lead_value' => 9000, 'branch' => 'Online']);
    F::leadProduct($l, $caro, 1, 9000);

    $ids = fn (string $orden) => collect(productos('ranking', "desde=2031-03-01&hasta=2031-03-31&orden={$orden}")->json('datos'))->pluck('producto_id')->all();

    expect($ids('monto'))->toBe([$caro, $this->especial, $this->merch, $this->sede])
        ->and($ids('unidades'))->toBe([$this->especial, $this->merch, $this->sede, $caro]);
});

it('filtra por clase, incluida programación especial', function () {
    $ids = fn (string $clase) => collect(productos('ranking', "desde=2031-03-01&hasta=2031-03-31&clase={$clase}")->json('datos'))->pluck('producto_id')->all();

    expect($ids('merch'))->toBe([$this->merch])
        ->and($ids('entrada'))->toBe([$this->especial, $this->sede])
        ->and($ids('programacion_especial'))->toBe([$this->especial]);
});

it('da la tendencia mensual contra el mismo mes del año anterior', function () {
    $datos = productos('tendencia', "producto={$this->merch}&desde=2031-03-01&hasta=2031-04-30")->assertOk()->json('datos');

    expect($datos)->toBe([
        ['producto_id' => $this->merch, 'nombre' => 'Remera', 'mes' => '2031-03', 'unidades' => 2, 'unidades_anio_anterior' => 4],
        ['producto_id' => $this->merch, 'nombre' => 'Remera', 'mes' => '2031-04', 'unidades' => 0, 'unidades_anio_anterior' => 0],
    ]);
});

it('la tendencia exige producto', function () {
    productos('tendencia', 'desde=2031-03-01&hasta=2031-04-30')
        ->assertStatus(422)->assertJsonPath('error', 'filtro_invalido');
});

it('lista productos con ventas históricas y sin ventas en el rango', function () {
    $ids = collect(productos('sin-movimiento', 'desde=2031-03-01&hasta=2031-03-31&limite=100')->assertOk()->json('datos'))->pluck('producto_id');

    expect($ids)->toContain($this->viejo)
        ->not->toContain($this->merch)
        ->not->toContain($this->nunca);
});

it('sin movimiento no cuenta ventas Excluida como historia', function () {
    $x = F::wonLead('2030-08-10 10:00:00', ['lead_value' => 1000, 'branch' => 'Excluida']);
    F::leadProduct($x, $this->nunca, 3, 1000);

    $ids = collect(productos('sin-movimiento', 'desde=2031-03-01&hasta=2031-03-31&limite=100')->assertOk()->json('datos'))->pluck('producto_id');

    expect($ids)->toContain($this->viejo)->not->toContain($this->nunca);
});

it('sin movimiento aplica los mismos filtros a la historia y al rango', function () {
    // Llavero: se vende en órdenes de merch antes y durante el rango.
    $llavero = F::product(['name' => 'Llavero', 'product_kind' => 'merch']);
    $h = F::wonLead('2030-05-10 10:00:00', ['lead_value' => 100, 'branch' => 'Online', 'order_type' => 'merch']);
    F::leadProduct($h, $llavero, 1, 100);
    $r = F::wonLead('2031-03-10 10:00:00', ['lead_value' => 100, 'branch' => 'Online', 'order_type' => 'merch']);
    F::leadProduct($r, $llavero, 1, 100);

    $ids = fn (string $filtro) => collect(productos('sin-movimiento', "desde=2031-03-01&hasta=2031-03-31&limite=100&{$filtro}")->assertOk()->json('datos'))->pluck('producto_id');

    // Dentro del universo de entradas el llavero no tiene historia: no es "sin movimiento".
    expect($ids('tipo=entradas'))->not->toContain($llavero)
        // Dentro del universo de merch se vendió en el rango: tampoco.
        ->and($ids('tipo=merch'))->not->toContain($llavero)
        // La taza solo tiene historia en Online: en San Cosmos no cuenta.
        ->and($ids('sucursal=San%20Cosmos'))->not->toContain($this->viejo)
        ->and($ids('sucursal=Online'))->toContain($this->viejo);
});

it('muestra el estado que carga Operaciones', function () {
    DB::table('operations_product_states')->insert([
        'product_id' => $this->merch, 'status' => 'pendiente', 'user_id' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $datos = collect(productos('estado', "producto={$this->merch},{$this->sede}")->assertOk()->json('datos'))->keyBy('producto_id');

    expect($datos[$this->merch]['estado'])->toBe('pendiente')
        ->and($datos[$this->sede]['estado'])->toBe('activo')
        ->and($datos[$this->merch])->not->toHaveKey('user_id');
});
