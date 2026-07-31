<?php

use CarlVallory\KrayinOperations\Models\ProductNote;
use CarlVallory\KrayinOperations\Queries\StaleProductQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
});

it('incluye productos sin ninguna venta (unidades = 0, ultima_venta NULL)', function () {
    $productId = DB::table('products')->insertGetId([
        'sku' => 'ROT-TEST-0', 'name' => 'Sin ventas', 'quantity' => 50,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $row = collect(StaleProductQuery::ranking()->get())->firstWhere('product_id', $productId);

    expect($row)->not->toBeNull();
    expect((int) $row->unidades)->toBe(0);
    expect($row->ultima_venta)->toBeNull();
    expect((int) $row->stock)->toBe(50);
});

it('cuenta unidades won dentro del rango y expone la última venta histórica', function () {
    $productId = DB::table('products')->insertGetId([
        'sku' => 'ROT-TEST-1', 'name' => 'Con ventas', 'quantity' => 5,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $leadEne = DB::table('leads')->insertGetId([
        'title' => 'W-ene', 'lead_value' => 0, 'net_value' => 0, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
        'closed_at' => '2026-01-10 09:00:00', 'created_at' => '2026-01-10 09:00:00', 'updated_at' => now(),
    ]);
    $leadMar = DB::table('leads')->insertGetId([
        'title' => 'W-mar', 'lead_value' => 0, 'net_value' => 0, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
        'closed_at' => '2026-03-10 09:00:00', 'created_at' => '2026-03-10 09:00:00', 'updated_at' => now(),
    ]);
    DB::table('lead_products')->insert([
        ['lead_id' => $leadEne, 'product_id' => $productId, 'quantity' => 3, 'price' => 1000, 'amount' => 3000, 'created_at' => now(), 'updated_at' => now()],
        ['lead_id' => $leadMar, 'product_id' => $productId, 'quantity' => 2, 'price' => 1000, 'amount' => 2000, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Rango enero: sólo 3 unidades; pero ultima_venta histórica = marzo.
    $row = collect(StaleProductQuery::ranking(['date_from' => '2026-01-01', 'date_to' => '2026-01-31'])->get())
        ->firstWhere('product_id', $productId);

    expect((int) $row->unidades)->toBe(3);
    expect($row->ultima_venta)->toContain('2026-03-10');
});

it('refleja la nota del producto vía left join', function () {
    $productId = DB::table('products')->insertGetId([
        'sku' => 'ROT-TEST-N', 'name' => 'Con nota', 'quantity' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    ProductNote::create(['product_id' => $productId, 'user_id' => 1, 'body' => 'Revisar']);

    $row = collect(StaleProductQuery::ranking()->get())->firstWhere('product_id', $productId);

    expect($row->nota)->toBe('Revisar');
});
