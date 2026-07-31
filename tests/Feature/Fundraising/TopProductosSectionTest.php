<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');

    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
    DB::table('products')->insert([
        ['id' => 910, 'name' => 'Producto Grid', 'sku' => 'PG-910', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('persons')->insert([
        ['id' => 950, 'name' => 'Comprador Grid', 'organization_id' => null, 'emails' => json_encode([]), 'contact_numbers' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('leads')->insert([
        ['id' => 8200, 'title' => 'GridLead', 'person_id' => 950, 'lead_value' => 0, 'net_value' => 100000, 'total_usd' => 0, 'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5, 'closed_at' => '2026-01-20 09:00:00', 'created_at' => '2026-01-20 09:00:00', 'updated_at' => now()],
    ]);
    DB::table('lead_products')->insert([
        ['lead_id' => 8200, 'product_id' => 910, 'quantity' => 4, 'price' => 25000, 'amount' => 100000, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

it('el ajax con ?seccion=productos devuelve el datagrid de productos', function () {
    $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->getJson(route('krayin.fundraising.index', ['seccion' => 'productos']));

    $response->assertOk();
    $response->assertJsonStructure(['records']);

    $records = collect($response->json('records'));
    expect($records->pluck('product_id'))->toContain(910);
});

it('el ajax por defecto (compradores) sigue devolviendo el datagrid de prospectos', function () {
    $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->getJson(route('krayin.fundraising.index', ['seccion' => 'compradores', 'ver' => 'personas']));

    $response->assertOk();
    $response->assertJsonStructure(['records']);

    $records = collect($response->json('records'));
    expect($records->pluck('prospect_id'))->toContain(950);
});

it('la vista muestra el selector de sección', function () {
    $response = $this->get(route('krayin.fundraising.index'));

    $response->assertOk();
    $response->assertSee('Top compradores', false);
    $response->assertSee('Top productos', false);
    $response->assertSee('seccion=productos', false);
});
