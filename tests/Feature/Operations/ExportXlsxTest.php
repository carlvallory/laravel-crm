<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

it('exporta XLSX con content-type de spreadsheet', function () {
    $res = $this->actingAs(User::find(1), 'user')
        ->get(route('krayin.operations.rotation.export.xlsx'));

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('spreadsheetml');
});
