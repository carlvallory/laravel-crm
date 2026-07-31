<?php

use CarlVallory\KrayinOperations\Models\ProductState;
use CarlVallory\KrayinOperations\Queries\StaleProductQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

it('expone estado con default activo y filtra por pestaña', function () {
    $activo = DB::table('products')->insertGetId([
        'sku' => 'ST-ACT', 'name' => 'Activo', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $pend = DB::table('products')->insertGetId([
        'sku' => 'ST-PEND', 'name' => 'Pendiente', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $pape = DB::table('products')->insertGetId([
        'sku' => 'ST-PAPE', 'name' => 'Papelera', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    ProductState::create(['product_id' => $pend, 'user_id' => 1, 'status' => 'pendiente']);
    ProductState::create(['product_id' => $pape, 'user_id' => 1, 'status' => 'papelera']);

    // estado default
    $row = collect(StaleProductQuery::ranking()->get())->firstWhere('product_id', $activo);
    expect($row->estado)->toBe('activo');

    $ids = fn ($estado) => collect(StaleProductQuery::applyStatusFilter(StaleProductQuery::ranking(), $estado)->get())
        ->pluck('product_id')->all();

    // activos: incluye el sin registro, excluye pendiente y papelera
    expect($ids('activos'))->toContain($activo)->not->toContain($pend)->not->toContain($pape);
    // pendientes
    expect($ids('pendientes'))->toContain($pend)->not->toContain($activo)->not->toContain($pape);
    // papelera
    expect($ids('papelera'))->toContain($pape)->not->toContain($activo)->not->toContain($pend);
    // todos: incluye papelera
    expect($ids('todos'))->toContain($activo)->toContain($pend)->toContain($pape);
});

it('cuenta por estado (statusCounts)', function () {
    $base = StaleProductQuery::statusCounts();

    $pid = DB::table('products')->insertGetId([
        'sku' => 'ST-CNT', 'name' => 'Cnt', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    ProductState::create(['product_id' => $pid, 'user_id' => 1, 'status' => 'papelera']);

    $after = StaleProductQuery::statusCounts();

    expect($after['papelera'])->toBe($base['papelera'] + 1);
    expect($after['todos'])->toBe($base['todos'] + 1);
});
