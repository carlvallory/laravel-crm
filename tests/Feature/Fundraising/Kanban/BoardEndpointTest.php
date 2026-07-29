<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
});

it('la ruta del board existe y responde OK', function () {
    $response = $this->getJson(route('krayin.fundraising.kanban.index'));

    $response->assertOk();
});
