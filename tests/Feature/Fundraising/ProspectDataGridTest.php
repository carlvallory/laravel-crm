<?php

use CarlVallory\KrayinFundraising\DataGrids\ProspectDataGrid;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
    DB::table('persons')->insert([
        'id' => 995, 'name' => 'Prospecto Grid', 'organization_id' => null,
        'emails' => json_encode([]), 'contact_numbers' => json_encode([]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('leads')->insert([
        'title' => 'G1', 'person_id' => 995, 'lead_value' => 0, 'net_value' => 4_000_000, 'total_usd' => 550,
        'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
        'closed_at' => '2026-01-20 09:00:00', 'created_at' => '2026-01-20 09:00:00', 'updated_at' => now(),
    ]);
});

it('el datagrid de personas incluye al prospecto con su volumen', function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');

    request()->merge(['ver' => 'personas']);

    // La clase base `Webkul\DataGrid\DataGrid` no expone `toArray()`: el único punto de
    // entrada público es `process()`, que devuelve un JsonResponse (o un archivo si
    // `export` viene en el request). Decodificamos ese JSON para inspeccionar `records`.
    $response = app(ProspectDataGrid::class)->process();
    $data = json_decode($response->getContent(), true);

    $names = collect($data['records'])->pluck('name');
    expect($names)->toContain('Prospecto Grid');
});

it('el controller responde JSON del grid ante una petición ajax', function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');

    // El controller usa `request()->ajax()` (misma convención que el resto de
    // controllers de Admin, ej. LeadController), que depende de la cabecera
    // `X-Requested-With: XMLHttpRequest`. `getJson()` por sí solo solo agrega
    // `Accept: application/json` y NO esa cabecera, así que la simulamos.
    $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->getJson(route('krayin.fundraising.index', ['ver' => 'personas']));

    $response->assertOk();
    $response->assertJsonStructure(['records']);
});

it('el buscador global del datagrid no rompe la consulta (columna name es un alias de GROUP BY)', function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');

    // El toolbar del DataGrid de Webkul SIEMPRE renderiza un input de búsqueda global,
    // que el frontend envía como `filters[all][]=<term>`. Si alguna columna del grid
    // tiene `searchable => true` y su `index` es un alias de SELECT sobre una query con
    // GROUP BY (como `name` en ProspectQuery), `processRequestedFilters()` arma un
    // `orWhere('name', 'LIKE', ...)` que MySQL/MariaDB rechaza con
    // SQLSTATE[42S22] "Unknown column 'name' in 'where clause'", porque los alias de
    // SELECT no son válidos en WHERE. Este test reproduce exactamente esa ruta.
    $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->getJson(route('krayin.fundraising.index', [
            'ver' => 'personas',
            'filters' => ['all' => ['grid']],
        ]));

    $response->assertOk();
    $response->assertJsonStructure(['records']);
});
