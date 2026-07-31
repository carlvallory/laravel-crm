<?php

use CarlVallory\KrayinOperations\Queries\StaleProductQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
});

it('en antiguedad asc pone los NULL (nunca vendido) antes que cualquier fecha', function () {
    // Producto vendido (ultima_venta con fecha).
    $soldId = DB::table('products')->insertGetId([
        'sku' => 'ORD-SOLD', 'name' => 'Vendido', 'quantity' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $lead = DB::table('leads')->insertGetId([
        'title' => 'W', 'lead_value' => 0, 'net_value' => 0, 'status' => 1,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
        'closed_at' => '2026-05-10 09:00:00', 'created_at' => '2026-05-10 09:00:00', 'updated_at' => now(),
    ]);
    DB::table('lead_products')->insert([
        'lead_id' => $lead, 'product_id' => $soldId, 'quantity' => 1, 'price' => 1000, 'amount' => 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Producto nunca vendido → ultima_venta NULL.
    $neverId = DB::table('products')->insertGetId([
        'sku' => 'ORD-NEVER', 'name' => 'Nunca vendido', 'quantity' => 5,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $q = StaleProductQuery::ranking();
    $q = StaleProductQuery::applyOrder($q, ['orden1' => 'antiguedad', 'dir1' => 'asc']);
    $rows = collect($q->get());

    $neverIndex = $rows->search(fn ($r) => (int) $r->product_id === $neverId);
    $soldIndex = $rows->search(fn ($r) => (int) $r->product_id === $soldId);

    // Todos los NULL van antes que cualquier fecha → el nunca-vendido antes que el vendido.
    expect($neverIndex)->toBeLessThan($soldIndex);
});

it('ordena por vendidas asc (menos vendido primero)', function () {
    $q = StaleProductQuery::ranking();
    $q = StaleProductQuery::applyOrder($q, ['orden1' => 'vendidas', 'dir1' => 'asc']);
    $rows = collect($q->get());

    $unidades = $rows->pluck('unidades')->map(fn ($u) => (int) $u)->all();
    $sorted = $unidades;
    sort($sorted);

    expect($unidades)->toBe($sorted);
});
