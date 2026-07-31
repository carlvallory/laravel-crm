<?php

use CarlVallory\KrayinOperations\DataGrids\StaleProductDataGrid;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

function gridUserWithout(string $email): User
{
    $roleId = DB::table('roles')->insertGetId([
        'name'            => 'Sin permiso grid',
        'permission_type' => 'custom',
        'permissions'     => json_encode(['dashboard']),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $userId = DB::table('users')->insertGetId([
        'name'       => 'Limitado grid',
        'email'      => $email,
        'role_id'    => $roleId,
        'status'     => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return User::find($userId);
}

it('renderiza el textarea de nota cuando el usuario tiene permiso', function () {
    $productId = DB::table('products')->insertGetId([
        'sku' => 'GRID-1', 'name' => 'Grid producto', 'quantity' => 3,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs(User::find(1), 'user');

    $response = app(StaleProductDataGrid::class)->process();
    $data = json_decode($response->getContent(), true);

    $columnIndexes = collect($data['columns'])->pluck('index');
    expect($columnIndexes)->toContain('nota');

    $record = collect($data['records'])->firstWhere('product_id', $productId);
    expect($record)->not->toBeNull();
    expect($record['nota'])->toContain('data-operations-note');
    expect($record['nota'])->toContain('data-product-id="'.$productId.'"');
});

it('renderiza la nota read-only (sin textarea) cuando el usuario no tiene permiso', function () {
    $productId = DB::table('products')->insertGetId([
        'sku' => 'GRID-2', 'name' => 'Grid producto 2', 'quantity' => 3,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('operations_product_notes')->insert([
        'product_id' => $productId, 'user_id' => 1, 'body' => 'Solo lectura',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs(gridUserWithout('grid-nope@test.local'), 'user');

    $response = app(StaleProductDataGrid::class)->process();
    $data = json_decode($response->getContent(), true);

    $record = collect($data['records'])->firstWhere('product_id', $productId);
    expect($record['nota'])->not->toContain('data-operations-note');
    expect($record['nota'])->toContain('Solo lectura');
});
