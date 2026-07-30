<?php

use CarlVallory\KrayinOperations\Models\ProductNote;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

it('crea y actualiza la nota vía endpoint (upsert)', function () {
    $pid = DB::table('products')->insertGetId([
        'sku' => 'NOTE-1', 'name' => 'Note prod', 'quantity' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs(User::find(1), 'user')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->postJson(route('krayin.operations.rotation.note.store', $pid), ['body' => 'Hola'])
        ->assertOk()
        ->assertJsonPath('note.body', 'Hola');

    expect(ProductNote::where('product_id', $pid)->count())->toBe(1);

    // Segundo POST → actualiza, no duplica.
    $this->actingAs(User::find(1), 'user')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->postJson(route('krayin.operations.rotation.note.store', $pid), ['body' => 'Chau'])
        ->assertOk()
        ->assertJsonPath('note.body', 'Chau');

    expect(ProductNote::where('product_id', $pid)->count())->toBe(1);
});

it('body vacío borra la nota', function () {
    $pid = DB::table('products')->insertGetId([
        'sku' => 'NOTE-2', 'name' => 'Note prod 2', 'quantity' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    ProductNote::create(['product_id' => $pid, 'user_id' => 1, 'body' => 'x']);

    $this->actingAs(User::find(1), 'user')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->postJson(route('krayin.operations.rotation.note.store', $pid), ['body' => ''])
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(ProductNote::where('product_id', $pid)->count())->toBe(0);
});
