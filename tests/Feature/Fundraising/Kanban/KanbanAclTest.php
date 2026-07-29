<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

function limitedUserWithoutKanban(): \Webkul\User\Models\User
{
    $roleId = DB::table('roles')->insertGetId([
        'name'            => 'Sin Fundraising',
        'permission_type' => 'custom',
        'permissions'     => json_encode(['dashboard']),  // no incluye fundraising.kanban
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $userId = DB::table('users')->insertGetId([
        'name'       => 'Limitado',
        'email'      => 'limitado@muci.org',
        'role_id'    => $roleId,
        'status'     => 1,   // activo: si no, el middleware `user` redirige (302) en vez de gatear (401)
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return \Webkul\User\Models\User::find($userId);
}

it('un usuario sin permiso fundraising.kanban recibe 401 en el board', function () {
    $this->actingAs(limitedUserWithoutKanban(), 'user');

    $this->getJson(route('krayin.fundraising.kanban.index'))->assertStatus(401);
});

it('un usuario sin permiso fundraising.kanban no puede crear tarjetas', function () {
    $this->actingAs(limitedUserWithoutKanban(), 'user');

    $this->postJson(route('krayin.fundraising.kanban.cards.store'), [
        'person_id' => 1, 'column_id' => 1,
    ])->assertStatus(401);
});

it('el admin (rol all) conserva acceso al board', function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');

    $this->getJson(route('krayin.fundraising.kanban.index'))->assertOk();
});
