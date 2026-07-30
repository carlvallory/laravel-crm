<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

it('renderiza las 4 pestañas de estado con conteos', function () {
    $res = $this->actingAs(User::find(1), 'user')
        ->get(route('krayin.operations.rotation.index'))
        ->assertOk();

    $res->assertSee('Todos');
    $res->assertSee('Activos');
    $res->assertSee('Pendientes');
    $res->assertSee('Papelera');
    // el link de la pestaña papelera lleva ?estado=papelera
    $res->assertSee('estado=papelera', false);
});
