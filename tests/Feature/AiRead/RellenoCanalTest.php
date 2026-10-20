<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AiRead\AiReadFixtures as F;

uses(DatabaseTransactions::class);

/** Tablas de WooCommerce mínimas. TEMPORARY no hace commit implícito en MariaDB. */
function crearTablasWoo(): void
{
    $db = DB::connection();
    $db->statement('CREATE TEMPORARY TABLE IF NOT EXISTS wpzv_wc_orders (id BIGINT UNSIGNED PRIMARY KEY, status VARCHAR(20), payment_method VARCHAR(100), payment_method_title TEXT)');
    $db->statement('CREATE TEMPORARY TABLE IF NOT EXISTS wpzv_wc_orders_meta (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED, meta_key VARCHAR(255), meta_value TEXT, KEY (order_id))');
    $db->statement('CREATE TEMPORARY TABLE IF NOT EXISTS wpzv_woocommerce_order_items (order_item_id BIGINT UNSIGNED PRIMARY KEY, order_item_name TEXT, order_item_type VARCHAR(200), order_id BIGINT UNSIGNED, KEY (order_id))');
    $db->statement('CREATE TEMPORARY TABLE IF NOT EXISTS wpzv_woocommerce_order_itemmeta (meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_item_id BIGINT UNSIGNED, meta_key VARCHAR(255), meta_value LONGTEXT, KEY (order_item_id))');
    $db->statement('CREATE TEMPORARY TABLE IF NOT EXISTS wpzv_postmeta (meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED, meta_key VARCHAR(255), meta_value LONGTEXT, KEY (post_id))');
}

function ordenWoo(int $id, string $metodo, string $titulo, ?int $caja, ?string $metodoPos, array $items): void
{
    DB::table('wpzv_wc_orders')->insert(['id' => $id, 'status' => 'wc-completed', 'payment_method' => $metodo, 'payment_method_title' => $titulo]);
    if ($caja !== null) {
        DB::table('wpzv_wc_orders_meta')->insert(['order_id' => $id, 'meta_key' => '_fooeventspos_user_id', 'meta_value' => (string) $caja]);
        DB::table('wpzv_wc_orders_meta')->insert(['order_id' => $id, 'meta_key' => '_fooeventspos_payment_method', 'meta_value' => $metodoPos]);
    }
    foreach ($items as $i => [$productId, $variationId]) {
        $itemId = $id * 10 + $i;
        DB::table('wpzv_woocommerce_order_items')->insert(['order_item_id' => $itemId, 'order_item_name' => 'x', 'order_item_type' => 'line_item', 'order_id' => $id]);
        DB::table('wpzv_woocommerce_order_itemmeta')->insert(['order_item_id' => $itemId, 'meta_key' => '_product_id', 'meta_value' => (string) $productId]);
        DB::table('wpzv_woocommerce_order_itemmeta')->insert(['order_item_id' => $itemId, 'meta_key' => '_variation_id', 'meta_value' => (string) $variationId]);
    }
}

beforeEach(function () {
    F::useDefaultConnection();
    crearTablasWoo();
    F::pointsOfSale([['wc_user_id' => 729, 'sucursal' => 'San Cosmos', 'merch_point' => 'Giftshop']]);

    // Orden de caja con una entrada (producto simple sin SKU → WC-800001).
    $this->entrada = F::product(['sku' => 'WC-800001']);
    $this->leadCaja = F::lead(['title' => 'Cliente: A - Orden #900101', 'lead_value' => 5000, 'created_at' => '2031-03-02 10:00:00']);
    F::leadProduct($this->leadCaja, $this->entrada, 1, 5000);
    ordenWoo(900101, 'fooeventspos_cash', 'Efectivo', 729, 'cash', [[800001, 0]]);
    DB::table('wpzv_postmeta')->insert(['post_id' => 800001, 'meta_key' => 'WooCommerceEventsEvent', 'meta_value' => 'Event']);

    // Orden online, cortesía (valor 0), con una variación que tiene SKU propio.
    $this->remera = F::product(['sku' => 'REM-AZ-M']);
    $this->leadOnline = F::lead(['title' => 'Cliente: B - Orden #900102', 'lead_value' => 0, 'created_at' => '2031-03-03 10:00:00']);
    F::leadProduct($this->leadOnline, $this->remera, 2, 0);
    ordenWoo(900102, 'bancard', 'Bancard', null, null, [[800002, 800003]]);
    DB::table('wpzv_postmeta')->insert(['post_id' => 800003, 'meta_key' => '_sku', 'meta_value' => 'REM-AZ-M']);

    // Lead sin orden en WooCommerce.
    $this->leadHuerfano = F::lead(['title' => 'Cliente: C - Orden #900199', 'created_at' => '2031-03-04 10:00:00']);
});

it('en simulación no escribe nada y muestra los conteos', function () {
    $escrituras = 0;
    DB::listen(function ($q) use (&$escrituras) {
        if (preg_match('/^\s*(update|insert|delete)/i', $q->sql)) {
            $escrituras++;
        }
    });

    $this->artisan('crm:ia-rellenar-canal', ['--desde' => '2031-03-01', '--hasta' => '2031-03-31', '--pausa' => 0])
        ->expectsOutputToContain('SIMULACIÓN')
        ->expectsOutputToContain('San Cosmos')
        ->expectsOutputToContain('Leads sin orden en WooCommerce: 1')
        ->assertSuccessful();

    expect($escrituras)->toBe(0)
        ->and(DB::table('leads')->where('id', $this->leadCaja)->value('branch'))->toBeNull();
});

it('con --escribir guarda datos crudos, canal y tipo de producto', function () {
    $this->artisan('crm:ia-rellenar-canal', ['--escribir' => true, '--desde' => '2031-03-01', '--hasta' => '2031-03-31', '--pausa' => 0])
        ->assertSuccessful();

    $caja = DB::table('leads')->find($this->leadCaja);
    $online = DB::table('leads')->find($this->leadOnline);

    expect([$caja->pos_user_id, $caja->payment_method, $caja->branch, $caja->sale_variation, $caja->order_type])
        ->toBe([729, 'cash', 'San Cosmos', 'En puerta', 'entradas'])
        ->and([$online->pos_user_id, $online->payment_method, $online->payment_method_title, $online->branch, $online->sale_variation, $online->order_type])
        ->toBe([null, 'bancard', 'Bancard', 'Online', 'Cortesía', 'merch'])
        ->and(DB::table('products')->where('id', $this->entrada)->value('product_kind'))->toBe('entrada')
        ->and(DB::table('products')->where('id', $this->remera)->value('product_kind'))->toBe('merch')
        ->and(DB::table('leads')->where('id', $this->leadHuerfano)->value('branch'))->toBeNull();
});

it('es idempotente', function () {
    $args = ['--escribir' => true, '--desde' => '2031-03-01', '--hasta' => '2031-03-31', '--pausa' => 0];
    $this->artisan('crm:ia-rellenar-canal', $args)->assertSuccessful();
    $antes = DB::table('leads')->whereIn('id', [$this->leadCaja, $this->leadOnline])->orderBy('id')->get(['branch', 'sale_variation', 'order_type']);

    $this->artisan('crm:ia-rellenar-canal', $args)->assertSuccessful();
    $despues = DB::table('leads')->whereIn('id', [$this->leadCaja, $this->leadOnline])->orderBy('id')->get(['branch', 'sale_variation', 'order_type']);

    expect($despues->toArray())->toEqual($antes->toArray());
});

it('resuelve todos los tipos de producto antes de clasificar, aunque crucen lotes', function () {
    // P se clasifica recién con la orden del tercer lead, pero el primero ya lo usa.
    $p = F::product(['sku' => 'WC-800021']);
    $q = F::product(['sku' => 'WC-800022']);

    $l1 = F::lead(['title' => 'Cliente: D - Orden #900201', 'lead_value' => 1000, 'created_at' => '2031-04-02 10:00:00']);
    F::leadProduct($l1, $p, 1, 1000);
    ordenWoo(900201, 'bancard', 'Bancard', null, null, [[800030, 0]]); // sin producto CRM asociado

    $l2 = F::lead(['title' => 'Cliente: E - Orden #900202', 'lead_value' => 1000, 'created_at' => '2031-04-03 10:00:00']);
    F::leadProduct($l2, $q, 1, 1000);
    ordenWoo(900202, 'bancard', 'Bancard', null, null, [[800022, 0]]);

    $l3 = F::lead(['title' => 'Cliente: F - Orden #900203', 'lead_value' => 1000, 'created_at' => '2031-04-04 10:00:00']);
    F::leadProduct($l3, $p, 1, 1000);
    ordenWoo(900203, 'bancard', 'Bancard', null, null, [[800021, 0]]);
    DB::table('wpzv_postmeta')->insert(['post_id' => 800021, 'meta_key' => 'WooCommerceEventsEvent', 'meta_value' => 'Event']);

    $this->artisan('crm:ia-rellenar-canal', ['--escribir' => true, '--desde' => '2031-04-01', '--hasta' => '2031-04-30', '--lote' => 1, '--pausa' => 0])
        ->assertSuccessful();

    expect(DB::table('leads')->whereIn('id', [$l1, $l2, $l3])->orderBy('id')->pluck('order_type')->all())
        ->toBe(['entradas', 'merch', 'entradas'])
        ->and(DB::table('products')->where('id', $p)->value('product_kind'))->toBe('entrada')
        ->and(DB::table('products')->where('id', $q)->value('product_kind'))->toBe('merch');
});

it('rechaza fechas mal escritas', function () {
    $this->artisan('crm:ia-rellenar-canal', ['--desde' => '2031-02-30'])->assertFailed();
});
