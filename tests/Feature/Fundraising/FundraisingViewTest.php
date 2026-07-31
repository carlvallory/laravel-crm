<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
});

it('muestra el toggle Personas/Organizaciones', function () {
    $response = $this->get(route('krayin.fundraising.index'));
    $response->assertOk();
    $response->assertSee('Personas');
    $response->assertSee('Organizaciones');
});

it('muestra el empty state en la vista de organizaciones', function () {
    $response = $this->get(route('krayin.fundraising.index', ['ver' => 'organizaciones']));
    $response->assertOk();
    $response->assertSee('Todavía no hay compradores institucionales', false);
});
