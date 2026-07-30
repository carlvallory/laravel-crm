<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

function limitedUserWithout(string $email): User
{
    $roleId = DB::table('roles')->insertGetId([
        'name'            => 'Sin permiso',
        'permission_type' => 'custom',
        'permissions'     => json_encode(['dashboard']),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $userId = DB::table('users')->insertGetId([
        'name'       => 'Limitado',
        'email'      => $email,
        'role_id'    => $roleId,
        'status'     => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return User::find($userId);
}

it('rechaza 401 sin el permiso operations.products.rotation', function () {
    $user = limitedUserWithout('ops-nope@test.local');

    $this->actingAs($user, 'user')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->getJson(route('krayin.operations.rotation.index'))
        ->assertStatus(401);
});

it('permite 200 al admin con todos los permisos', function () {
    $this->actingAs(User::find(1), 'user')
        ->get(route('krayin.operations.rotation.index'))
        ->assertStatus(200);
});
