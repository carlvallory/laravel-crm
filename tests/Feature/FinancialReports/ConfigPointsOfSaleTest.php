<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Core\Models\CoreConfig;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
});

it('persiste points_of_sale y descarta filas incompletas o con wc_user_id no entero', function () {
    $this->post(route('krayin.financial-reports.configure.store'), [
        'points_of_sale' => [
            ['wc_user_id' => '729', 'sucursal' => 'San Cosmos', 'merch_point' => 'Giftshop'],
            ['wc_user_id' => '3',   'sucursal' => 'Tatakualab', 'merch_point' => 'Tatakuashop'],
            ['wc_user_id' => '',    'sucursal' => 'Sin ID',     'merch_point' => 'X'],          // incompleta → descartada
            ['wc_user_id' => 'abc', 'sucursal' => 'No entero',  'merch_point' => 'Y'],          // no entero → descartada
            ['wc_user_id' => '5',   'sucursal' => '',           'merch_point' => 'Z'],          // sin sucursal → descartada
        ],
    ])->assertRedirect(route('krayin.financial-reports.index'));

    $stored = json_decode(
        CoreConfig::where('code', 'krayin_financial_reports.settings.points_of_sale')->value('value'),
        true
    );

    expect($stored)->toBe([
        ['wc_user_id' => 729, 'sucursal' => 'San Cosmos', 'merch_point' => 'Giftshop'],
        ['wc_user_id' => 3,   'sucursal' => 'Tatakualab', 'merch_point' => 'Tatakuashop'],
    ]);
});

it('rechaza el guardado si hay wc_user_id duplicado y no persiste points_of_sale', function () {
    $response = $this->post(route('krayin.financial-reports.configure.store'), [
        'points_of_sale' => [
            ['wc_user_id' => '729', 'sucursal' => 'San Cosmos', 'merch_point' => 'Giftshop'],
            ['wc_user_id' => '729', 'sucursal' => 'Duplicado',  'merch_point' => 'Otro'],
        ],
    ]);

    $response->assertRedirect(); // vuelve atrás con error
    $response->assertSessionHas('error');

    expect(CoreConfig::where('code', 'krayin_financial_reports.settings.points_of_sale')->exists())
        ->toBeFalse();
});
