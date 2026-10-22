<?php

use Illuminate\Support\Str;

it('expone exactamente las 10 rutas de lectura, todas GET', function () {
    $rutas = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => Str::startsWith($r->uri(), 'api/ia/'));

    foreach ($rutas as $ruta) {
        expect(array_diff($ruta->methods(), ['GET', 'HEAD']))->toBe([], "{$ruta->uri()} acepta otro verbo");
    }

    expect($rutas->map->uri()->sort()->values()->all())->toBe([
        'api/ia/v1/actividades/resumen',
        'api/ia/v1/contactos/resumen',
        'api/ia/v1/conversion',
        'api/ia/v1/fundraising/resumen',
        'api/ia/v1/pipeline/resumen',
        'api/ia/v1/productos/estado',
        'api/ia/v1/productos/ranking',
        'api/ia/v1/productos/sin-movimiento',
        'api/ia/v1/productos/tendencia',
        'api/ia/v1/ventas/resumen',
    ]);
});

it('todas las rutas exigen token', function () {
    collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => Str::startsWith($r->uri(), 'api/ia/'))
        ->each(fn ($r) => test()->getJson('/'.$r->uri())->assertStatus(401));
});
