<?php

use CarlVallory\KrayinAiRead\Classification\EavReader;
use CarlVallory\KrayinAiRead\Classification\PointsOfSale;
use CarlVallory\KrayinAiRead\Classification\ProductTags;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AiRead\AiReadFixtures as F;

uses(DatabaseTransactions::class);

it('arma el mapa caja → sucursal desde Puntos de Venta', function () {
    F::pointsOfSale([
        ['wc_user_id' => 729, 'sucursal' => 'San Cosmos', 'merch_point' => 'Giftshop'],
        ['wc_user_id' => 3,   'sucursal' => ' Tatakualab ', 'merch_point' => 'Tatakuashop'],
        ['sucursal' => 'Sin id', 'merch_point' => 'X'],
    ]);

    expect(PointsOfSale::branchMap())->toBe([729 => 'San Cosmos', 3 => 'Tatakualab'])
        ->and(PointsOfSale::configured())->toBeTrue();
});

it('sin Puntos de Venta devuelve un mapa vacío', function () {
    DB::table('core_config')->where('code', PointsOfSale::CONFIG_CODE)->delete();

    expect(PointsOfSale::branchMap())->toBe([])
        ->and(PointsOfSale::configured())->toBeFalse();
});

it('con JSON roto devuelve un mapa vacío', function () {
    DB::table('core_config')->updateOrInsert(['code' => PointsOfSale::CONFIG_CODE], ['value' => '{roto']);

    expect(PointsOfSale::branchMap())->toBe([]);
});

it('junta los productos de los tags de sede, sin importar mayúsculas ni tildes', function () {
    F::productTags([
        'San Cosmos'            => [12, 13],
        'TATAKUALAB'            => [13, 14],
        'Programación Especial' => [99],
    ]);

    expect(ProductTags::sedeProductIds())->toBe([12, 13, 14]);
});

it('lee valores EAV de texto y trata el vacío como null', function () {
    $leadId = F::lead();
    F::eav('leads', $leadId, 'wc_pos_user_id', '729');
    F::eav('leads', $leadId, 'wc_payment_method', '  ');

    expect(EavReader::texts('leads', $leadId, ['wc_pos_user_id', 'wc_payment_method', 'wc_payment_method_title']))
        ->toBe(['wc_pos_user_id' => '729', 'wc_payment_method' => null, 'wc_payment_method_title' => null]);
});
