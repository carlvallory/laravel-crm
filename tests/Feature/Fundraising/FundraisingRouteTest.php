<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('carga la pestaña de fundraising para un usuario logueado', function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');

    $response = $this->get(route('krayin.fundraising.index'));

    $response->assertOk();
    $response->assertSee('Fundraising');
});

it('rechaza el acceso sin sesión', function () {
    $response = $this->get(route('krayin.fundraising.index'));

    $response->assertRedirect();
});
