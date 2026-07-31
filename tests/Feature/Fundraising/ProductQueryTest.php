<?php

use CarlVallory\KrayinFundraising\Queries\ProductQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 2], [
        'code' => 'follow_up', 'name' => 'Follow Up', 'lead_pipeline_id' => 1, 'sort_order' => 2,
    ]);

    DB::table('products')->insert([
        ['id' => 900, 'name' => 'Entrada Planetario', 'sku' => 'ENT-900', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 901, 'name' => 'Remera MuCi',        'sku' => 'REM-901', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Leads won (enero y marzo) + 1 lead NO-won que debe ignorarse.
    DB::table('leads')->insert([
        ['id' => 8100, 'title' => 'W-ene', 'lead_value' => 0, 'net_value' => 0, 'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5, 'closed_at' => '2026-01-10 09:00:00', 'created_at' => '2026-01-10 09:00:00', 'updated_at' => now()],
        ['id' => 8101, 'title' => 'W-mar', 'lead_value' => 0, 'net_value' => 0, 'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5, 'closed_at' => '2026-03-10 09:00:00', 'created_at' => '2026-03-10 09:00:00', 'updated_at' => now()],
        ['id' => 8102, 'title' => 'NoWon', 'lead_value' => 0, 'net_value' => 0, 'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 2, 'closed_at' => null, 'created_at' => '2026-01-11 09:00:00', 'updated_at' => now()],
    ]);

    DB::table('lead_products')->insert([
        // won enero: 900 x3, 901 x1
        ['lead_id' => 8100, 'product_id' => 900, 'quantity' => 3, 'price' => 50000, 'amount' => 150000, 'created_at' => now(), 'updated_at' => now()],
        ['lead_id' => 8100, 'product_id' => 901, 'quantity' => 1, 'price' => 100000, 'amount' => 100000, 'created_at' => now(), 'updated_at' => now()],
        // won marzo: 900 x2
        ['lead_id' => 8101, 'product_id' => 900, 'quantity' => 2, 'price' => 50000, 'amount' => 100000, 'created_at' => now(), 'updated_at' => now()],
        // NO-won: 900 x10 (debe ignorarse)
        ['lead_id' => 8102, 'product_id' => 900, 'quantity' => 10, 'price' => 50000, 'amount' => 500000, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

it('agrega unidades e ingresos por producto sobre leads won, ordenado por unidades desc', function () {
    $rows = ProductQuery::topProducts()->orderByDesc('units')->get();

    // Producto 900: 3+2 = 5 unidades (ignora el no-won x10); 901: 1
    $byId = $rows->keyBy('product_id');
    expect((int) $byId[900]->units)->toBe(5);
    expect((float) $byId[900]->revenue)->toBe(250000.0);   // 3*50000 + 2*50000
    expect((int) $byId[901]->units)->toBe(1);

    // Orden por unidades desc: 900 primero
    expect((int) $rows->first()->product_id)->toBe(900);
});

it('filtra por rango de fecha (closed_at)', function () {
    $rows = ProductQuery::topProducts(['date_from' => '2026-01-01', 'date_to' => '2026-01-31'])
        ->get()->keyBy('product_id');

    expect((int) $rows[900]->units)->toBe(3);   // solo el lead de enero
    expect($rows->has(901))->toBeTrue();
});
