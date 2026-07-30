<?php

use CarlVallory\KrayinOperations\DataGrids\StaleProductDataGrid;
use CarlVallory\KrayinOperations\Models\ProductState;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

it('renderiza el select de estado con permiso y respeta la pestaña ?estado', function () {
    $pid = DB::table('products')->insertGetId([
        'sku' => 'GST-1', 'name' => 'Grid estado', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    ProductState::create(['product_id' => $pid, 'user_id' => 1, 'status' => 'papelera']);

    $this->actingAs(User::find(1), 'user');

    // pestaña papelera → el producto aparece con el select
    request()->merge(['estado' => 'papelera']);
    $data = json_decode(app(StaleProductDataGrid::class)->process()->getContent(), true);
    expect(collect($data['columns'])->pluck('index'))->toContain('estado');
    $rec = collect($data['records'])->firstWhere('product_id', $pid);
    expect($rec['estado'])->toContain('data-operations-state');

    // pestaña activos → NO aparece
    request()->merge(['estado' => 'activos']);
    $data2 = json_decode(app(StaleProductDataGrid::class)->process()->getContent(), true);
    expect(collect($data2['records'])->firstWhere('product_id', $pid))->toBeNull();
});
