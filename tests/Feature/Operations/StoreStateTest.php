<?php

use CarlVallory\KrayinOperations\Models\ProductState;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

it('upsert de estado vía endpoint y activo limpia', function () {
    $pid = DB::table('products')->insertGetId([
        'sku' => 'STE-1', 'name' => 'St prod', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs(User::find(1), 'user')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->postJson(route('krayin.operations.rotation.state.store', $pid), ['status' => 'papelera'])
        ->assertOk()->assertJsonPath('state.status', 'papelera');

    expect(ProductState::where('product_id', $pid)->count())->toBe(1);

    $this->actingAs(User::find(1), 'user')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->postJson(route('krayin.operations.rotation.state.store', $pid), ['status' => 'activo'])
        ->assertOk()->assertJsonPath('reset', true);

    expect(ProductState::where('product_id', $pid)->count())->toBe(0);
});

it('rechaza status inválido con 422', function () {
    $pid = DB::table('products')->insertGetId([
        'sku' => 'STE-2', 'name' => 'St prod 2', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs(User::find(1), 'user')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->postJson(route('krayin.operations.rotation.state.store', $pid), ['status' => 'basura'])
        ->assertStatus(422);
});
