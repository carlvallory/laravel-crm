<?php

use CarlVallory\KrayinAiRead\Classification\LeadChannelUpdater;
use CarlVallory\KrayinAiRead\Classification\PointsOfSale;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\AiRead\AiReadFixtures as F;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Models\ProductProxy as LeadProductProxy;
use Webkul\Product\Models\ProductProxy;

uses(DatabaseTransactions::class);

beforeEach(function () {
    F::pointsOfSale([['wc_user_id' => 729, 'sucursal' => 'San Cosmos', 'merch_point' => 'Giftshop']]);
});

function canal(int $leadId): array
{
    return (array) DB::table('leads')->where('id', $leadId)
        ->first(['pos_user_id', 'payment_method', 'payment_method_title', 'branch', 'sale_variation', 'order_type']);
}

it('clasifica un lead de caja al crearse', function () {
    $id = F::lead(['lead_value' => 5000]);
    F::eav('leads', $id, 'wc_pos_user_id', '729');
    F::eav('leads', $id, 'wc_payment_method', 'cash');
    F::eav('leads', $id, 'wc_payment_method_title', 'Efectivo');

    Event::dispatch('lead.create.after', LeadProxy::modelClass()::find($id));

    expect(canal($id))->toBe([
        'pos_user_id' => 729, 'payment_method' => 'cash', 'payment_method_title' => 'Efectivo',
        'branch' => 'San Cosmos', 'sale_variation' => 'En puerta', 'order_type' => 'desconocido',
    ]);
});

it('sin Puntos de Venta deja la venta de caja en Sin asignar y no rompe', function () {
    DB::table('core_config')->where('code', PointsOfSale::CONFIG_CODE)->delete();
    $id = F::lead(['lead_value' => 5000]);
    F::eav('leads', $id, 'wc_pos_user_id', '729');

    Event::dispatch('lead.create.after', LeadProxy::modelClass()::find($id));

    expect(canal($id)['branch'])->toBe('Sin asignar');
});

it('una caja 0 o no numérica cuenta como online', function (string $pos) {
    $id = F::lead(['lead_value' => 5000]);
    F::eav('leads', $id, 'wc_pos_user_id', $pos);
    F::eav('leads', $id, 'wc_payment_method', 'bancard');

    Event::dispatch('lead.create.after', LeadProxy::modelClass()::find($id));

    expect(canal($id)['pos_user_id'])->toBeNull()
        ->and(canal($id)['branch'])->toBe('Online');
})->with(['0', 'abc', '-3']);

it('un lead rellenado y después editado a mano conserva su canal', function () {
    $id = F::lead([
        'lead_value' => 5000, 'pos_user_id' => 729, 'payment_method' => 'cash',
        'payment_method_title' => 'Efectivo', 'branch' => 'San Cosmos',
        'sale_variation' => 'En puerta', 'order_type' => 'desconocido',
    ]);

    Event::dispatch('lead.update.after', LeadProxy::modelClass()::find($id));

    expect(canal($id)['branch'])->toBe('San Cosmos')
        ->and(canal($id)['pos_user_id'])->toBe(729);
});

it('no clasifica leads cargados a mano', function () {
    $id = F::lead(['title' => 'Donación Banco X', 'lead_value' => 1000000]);

    Event::dispatch('lead.create.after', LeadProxy::modelClass()::find($id));

    expect(canal($id)['branch'])->toBeNull();
});

it('recalcula el tipo de orden al vincular productos', function () {
    $id = F::lead(['lead_value' => 5000]);
    F::eav('leads', $id, 'wc_payment_method', 'bancard');
    Event::dispatch('lead.create.after', LeadProxy::modelClass()::find($id));

    $merch = F::product(['product_kind' => 'merch']);
    $entrada = F::product(['product_kind' => 'entrada']);

    LeadProductProxy::modelClass()::create(['lead_id' => $id, 'product_id' => $merch, 'quantity' => 1, 'price' => 1000, 'amount' => 1000]);
    expect(canal($id)['order_type'])->toBe('merch');

    LeadProductProxy::modelClass()::create(['lead_id' => $id, 'product_id' => $entrada, 'quantity' => 1, 'price' => 4000, 'amount' => 4000]);
    expect(canal($id)['order_type'])->toBe('mixto');
});

it('un error al clasificar no rompe el guardado del lead', function () {
    $mock = Mockery::mock(LeadChannelUpdater::class);
    $mock->shouldReceive('reclassify')->andThrow(new RuntimeException('falla de prueba'));
    app()->instance(LeadChannelUpdater::class, $mock);

    $id = F::lead();

    expect(fn () => Event::dispatch('lead.create.after', LeadProxy::modelClass()::find($id)))->not->toThrow(Throwable::class);
});

it('copia wc_product_kind a products.product_kind y descarta valores inválidos', function () {
    $ok = F::product();
    $malo = F::product();
    F::eav('products', $ok, 'wc_product_kind', 'entrada');
    F::eav('products', $malo, 'wc_product_kind', 'otro');

    Event::dispatch('product.update.after', ProductProxy::modelClass()::find($ok));
    Event::dispatch('product.update.after', ProductProxy::modelClass()::find($malo));

    expect(DB::table('products')->where('id', $ok)->value('product_kind'))->toBe('entrada')
        ->and(DB::table('products')->where('id', $malo)->value('product_kind'))->toBeNull();
});

it('de punta a punta por la REST API: crear lead de caja y vincular producto', function () {
    actingAsSanctumAuthenticatedAdmin();
    $merch = F::product(['product_kind' => 'merch']);

    $response = $this->postJson('/api/v1/leads', [
        'title'                   => 'Cliente: Prueba REST - Orden #'.random_int(9000000, 9999999),
        'lead_value'              => 5000,
        'lead_source_id'          => DB::table('lead_sources')->value('id'),
        'lead_type_id'            => DB::table('lead_types')->value('id'),
        'lead_pipeline_stage_id'  => F::stageId('won'),
        'person'                  => [
            'name'   => 'Prueba REST',
            'emails' => [['value' => Str::random(12).'@example.test', 'label' => 'work']],
        ],
        'wc_pos_user_id'          => '729',
        'wc_payment_method'       => 'cash',
        'wc_payment_method_title' => 'Efectivo',
    ]);
    $response->assertSuccessful();
    $id = (int) $response->json('data.id');

    expect(canal($id)['branch'])->toBe('San Cosmos');

    $this->putJson("/api/v1/leads/product/{$id}", ['product_id' => $merch, 'quantity' => 1, 'price' => 5000])
        ->assertSuccessful();

    expect(canal($id)['order_type'])->toBe('merch');
});

it('de punta a punta por la REST API: crear producto con wc_product_kind', function () {
    actingAsSanctumAuthenticatedAdmin();

    $response = $this->postJson('/api/v1/products', [
        'name'            => 'Remera de prueba',
        'sku'             => 'T-'.Str::upper(Str::random(10)),
        'quantity'        => 1,
        'price'           => 100000,
        'wc_product_kind' => 'merch',
    ]);
    $response->assertSuccessful();

    expect(DB::table('products')->where('id', $response->json('data.id'))->value('product_kind'))->toBe('merch');
});
