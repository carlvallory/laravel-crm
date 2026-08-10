<?php

use Illuminate\Support\Facades\DB;

function woocommerceReachable(): bool
{
    try {
        DB::connection('woocommerce')->getPdo();

        return true;
    } catch (\Throwable) {
        return false;
    }
}

test('la conexión woocommerce está registrada', function () {
    expect(config('database.connections.woocommerce.database'))->toBe('muci');
    expect(config('database.connections.woocommerce.prefix'))->toBe('wpzv_');
});

test('la conexión lee la base muci', function () {
    if (! woocommerceReachable()) {
        $this->markTestSkipped('Base muci no disponible en este entorno.');
    }

    $count = DB::connection('woocommerce')->table('posts')
        ->where('post_type', 'event_magic_tickets')
        ->count();

    expect($count)->toBeGreaterThan(0);
});

test('la conexión no puede escribir en muci', function () {
    if (! woocommerceReachable()) {
        $this->markTestSkipped('Base muci no disponible en este entorno.');
    }

    expect(fn () => DB::connection('woocommerce')->statement('CREATE TABLE zz_probe_readonly (id INT)'))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
