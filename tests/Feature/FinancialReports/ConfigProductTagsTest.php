<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Core\Models\CoreConfig;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
});

it('persiste product_tags como mapa nombre→ids, con un producto en varios tags', function () {
    $this->post(route('krayin.financial-reports.configure.store'), [
        'product_tags' => [
            ['name' => 'San Cosmos',            'products' => [12, 13]],
            ['name' => 'Programación Especial', 'products' => [13]], // 13 en dos tags (muchos-a-muchos)
            ['name' => '',                       'products' => [99]], // sin nombre → se descarta
        ],
    ])->assertRedirect(route('krayin.financial-reports.index'));

    $stored = json_decode(
        CoreConfig::where('code', 'krayin_financial_reports.settings.product_tags')->value('value'),
        true
    );

    expect($stored)->toBe([
        'San Cosmos'            => [12, 13],
        'Programación Especial' => [13],
    ]);
});

it('no rompe el custom_sections existente al guardar', function () {
    $this->post(route('krayin.financial-reports.configure.store'), [
        'sections'     => ['1' => ['title' => 'Merch', 'products' => [7]]],
        'product_tags' => [['name' => 'San Cosmos', 'products' => [1]]],
    ])->assertRedirect(route('krayin.financial-reports.index'));

    $sections = json_decode(
        CoreConfig::where('code', 'krayin_financial_reports.settings.custom_sections')->value('value'),
        true
    );

    expect($sections['1']['title'])->toBe('Merch');
    expect($sections['1']['products'])->toBe([7]);
});
