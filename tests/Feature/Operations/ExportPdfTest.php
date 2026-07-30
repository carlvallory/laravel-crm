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

it('embebe la fuente Poppins Bold (directriz de branding org)', function () {
    $res = $this->actingAs(User::find(1), 'user')
        ->get(route('krayin.operations.rotation.export.pdf'));

    $body = $res->getContent();

    expect($body)->toStartWith('%PDF');
    // Si dompdf cayera al fallback Helvetica, este assert fallaría.
    expect($body)->toContain('Poppins-Bold');
});
