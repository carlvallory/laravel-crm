<?php

use CarlVallory\KrayinOperations\Models\ProductState;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('mantiene un solo estado por producto (upsert por product_id)', function () {
    ProductState::updateOrCreate(['product_id' => 999501], ['status' => 'pendiente', 'user_id' => 1]);
    ProductState::updateOrCreate(['product_id' => 999501], ['status' => 'papelera', 'user_id' => 1]);

    expect(ProductState::where('product_id', 999501)->count())->toBe(1);
    expect(ProductState::where('product_id', 999501)->first()->status)->toBe('papelera');
});
