<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

it('exporta PDF con content-type application/pdf', function () {
    $res = $this->actingAs(User::find(1), 'user')
        ->get(route('krayin.operations.rotation.export.pdf'));

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('application/pdf');
});
