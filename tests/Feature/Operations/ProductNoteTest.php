<?php

use CarlVallory\KrayinOperations\Models\ProductNote;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('mantiene una sola nota por producto (upsert por product_id)', function () {
    ProductNote::updateOrCreate(['product_id' => 999001], ['body' => 'v1', 'user_id' => 1]);
    ProductNote::updateOrCreate(['product_id' => 999001], ['body' => 'v2', 'user_id' => 1]);

    expect(ProductNote::where('product_id', 999001)->count())->toBe(1);
    expect(ProductNote::where('product_id', 999001)->first()->body)->toBe('v2');
});
