<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Core\Models\CoreConfig;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
});

it('la vista configure muestra los tabs de Product Tags y Puntos de Venta', function () {
    $response = $this->get(route('krayin.financial-reports.configure'));

    $response->assertOk();
    $response->assertSee('Product Tags', false);
    $response->assertSee('Puntos de Venta', false);
});

it('la vista configure precarga los tags y puntos de venta guardados', function () {
    CoreConfig::updateOrCreate(
        ['code' => 'krayin_financial_reports.settings.product_tags'],
        ['value' => json_encode(['San Cosmos' => [1, 2]])]
    );
    CoreConfig::updateOrCreate(
        ['code' => 'krayin_financial_reports.settings.points_of_sale'],
        ['value' => json_encode([['wc_user_id' => 729, 'sucursal' => 'San Cosmos', 'merch_point' => 'Giftshop']])]
    );

    $response = $this->get(route('krayin.financial-reports.configure'));

    $response->assertOk();
    $response->assertSee('San Cosmos', false);   // nombre del tag precargado
    $response->assertSee('Giftshop', false);      // merch_point precargado
    $response->assertSee('729', false);           // wc_user_id precargado
});
