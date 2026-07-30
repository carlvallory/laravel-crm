<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

it('renderiza la vista con barra de orden y botones export', function () {
    $res = $this->actingAs(User::find(1), 'user')
        ->get(route('krayin.operations.rotation.index'))
        ->assertOk();

    $res->assertSee('Productos sin rotación');
    $res->assertSee('Exportar PDF');
    $res->assertSee('Exportar XLSX');
    $res->assertSee('name="orden1"', false);
    $res->assertSee('name="orden2"', false);
});
