<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\AiRead\AiReadFixtures as F;

uses(DatabaseTransactions::class);

beforeEach(function () {
    F::useDefaultConnection();
    $this->token = F::token();
    $p = F::product(['product_kind' => 'merch']);
    $this->producto = $p;

    $canal = fn (string $branch, string $var, string $tipo = 'merch') => ['branch' => $branch, 'sale_variation' => $var, 'order_type' => $tipo];

    $a = F::wonLead('2031-03-05 10:00:00', ['lead_value' => 1000] + $canal('San Cosmos', 'En puerta'));
    F::leadProduct($a, $p, 2, 1000);
    $b = F::wonLead('2031-03-31 23:59:59', ['lead_value' => 3000] + $canal('Online', 'Online'));
    F::leadProduct($b, $p, 1, 3000);
    $c = F::wonLead('2031-03-10 12:00:00', ['lead_value' => 0] + $canal('Online', 'Cortesía'));
    F::leadProduct($c, $p, 1, 0);

    F::wonLead('2031-03-11 12:00:00', ['lead_value' => 9999] + $canal('Excluida', 'En puerta'));
    F::wonLead('2031-04-01 00:00:00', ['lead_value' => 7777] + $canal('Online', 'Online'));
    F::lead(['lead_pipeline_stage_id' => F::stageId('lost'), 'closed_at' => '2031-03-12 09:00:00', 'lead_value' => 2000] + $canal('Online', 'Online'));
    F::lead(['lead_value' => 5000]);
});

function ventas(string $ruta, string $query): \Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->getJson("/api/ia/v1/{$ruta}?{$query}")->assertOk();
}

it('resume ventas del mes: sin Lost, sin Excluida, cortesías aparte', function () {
    $r = ventas('ventas/resumen', 'desde=2031-03-01&hasta=2031-03-31');

    expect($r->json('datos'))->toBe([[
        'periodo' => '2031-03', 'monto' => 4000, 'ordenes' => 2,
        'ticket_promedio' => 2000, 'unidades' => 3, 'cortesias' => 1,
    ]])->and($r->json('avisos'))->toContain('Montos en guaraníes, sin IVA.');
});

it('incluye el último segundo de hasta', function () {
    $r = ventas('ventas/resumen', 'desde=2031-03-31&hasta=2031-03-31&agrupar=dia');

    expect($r->json('datos.0.monto'))->toBe(3000);
});

it('agrupa por día', function () {
    $periodos = collect(ventas('ventas/resumen', 'desde=2031-03-01&hasta=2031-03-31&agrupar=dia')->json('datos'))->pluck('periodo')->all();

    expect($periodos)->toBe(['2031-03-05', '2031-03-10', '2031-03-31']);
});

it('filtra por sucursal y por variación', function () {
    expect(ventas('ventas/resumen', 'desde=2031-03-01&hasta=2031-03-31&sucursal=San%20Cosmos')->json('datos.0.monto'))->toBe(1000)
        ->and(ventas('ventas/resumen', 'desde=2031-03-01&hasta=2031-03-31&variacion=online')->json('datos.0.monto'))->toBe(3000);
});

it('avisa de cajas sin asignar, tipos desconocidos y ventas sin canal', function () {
    F::wonLead('2031-03-15 10:00:00', ['lead_value' => 500, 'branch' => 'Sin asignar', 'pos_user_id' => 14616, 'sale_variation' => 'En puerta', 'order_type' => 'desconocido']);
    F::wonLead('2031-03-16 10:00:00', ['title' => 'Donación', 'lead_value' => 800]);

    $avisos = implode(' | ', ventas('ventas/resumen', 'desde=2031-03-01&hasta=2031-03-31')->json('avisos'));

    expect($avisos)->toContain('14616')->toContain('sin tipo de orden')->toContain('sin canal');
});

it('calcula la conversión con Won y Lost de leads con canal', function () {
    $r = ventas('conversion', 'desde=2031-03-01&hasta=2031-03-31');

    expect($r->json('datos'))->toBe([[
        'periodo' => '2031-03', 'concretadas' => 3, 'no_concretadas' => 1, 'tasa' => 0.75,
    ]]);
});

it('en conversión avisa que las ventas sin canal quedan afuera, no que cuentan en los totales', function () {
    F::wonLead('2031-03-16 10:00:00', ['title' => 'Donación', 'lead_value' => 800]);
    F::wonLead('2031-03-17 10:00:00', ['title' => 'Carga manual', 'lead_value' => 900]);
    F::lead(['lead_pipeline_stage_id' => F::stageId('lost'), 'closed_at' => '2031-03-18 10:00:00', 'lead_value' => 700]);

    $avisos = implode(' | ', ventas('conversion', 'desde=2031-03-01&hasta=2031-03-31')->json('avisos'));

    // ConversionQuery excluye los sin canal de Won y de Lost: el aviso cuenta los 3.
    expect($avisos)->toContain('3 ventas sin canal')
        ->toContain('No se incluyen en la conversión')
        ->not->toContain('Cuentan en los totales');

    // En el resumen (solo Won) siguen siendo 2.
    expect(implode(' | ', ventas('ventas/resumen', 'desde=2031-03-01&hasta=2031-03-31')->json('avisos')))
        ->toContain('2 ventas sin canal');
});

it('avisa que el filtro por producto suma la orden entera', function () {
    $otro = F::product(['product_kind' => 'merch']);
    $mixta = F::wonLead('2031-03-20 10:00:00', ['lead_value' => 5000, 'branch' => 'Online', 'sale_variation' => 'Online', 'order_type' => 'merch']);
    F::leadProduct($mixta, $this->producto, 1, 1000);
    F::leadProduct($mixta, $otro, 2, 2000);

    $r = ventas('ventas/resumen', "desde=2031-03-01&hasta=2031-03-31&producto={$this->producto}");

    expect($r->json('avisos'))->toContain(\CarlVallory\KrayinAiRead\Queries\AvisosVentas::PRODUCTO)
        ->and(ventas('ventas/resumen', 'desde=2031-03-01&hasta=2031-03-31')->json('avisos'))
        ->not->toContain(\CarlVallory\KrayinAiRead\Queries\AvisosVentas::PRODUCTO);
});

it('avisa que la conversión anterior a julio de 2026 no es comparable', function () {
    $avisos = ventas('conversion', 'desde=2026-06-01&hasta=2026-07-31')->json('avisos');

    expect(implode(' ', $avisos))->toContain('2026-07-01');
});
