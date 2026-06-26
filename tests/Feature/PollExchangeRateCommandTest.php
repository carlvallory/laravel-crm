<?php

use CarlVallory\KrayinNetValue\Models\ExchangeRate;
use CarlVallory\KrayinNetValue\Services\Bcp\BcpRateFetcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;

// Rollback automático: el delete() del beforeEach y los upserts no persisten.
uses(DatabaseTransactions::class);

beforeEach(fn () => ExchangeRate::query()->delete());

it('guarda la última cotización disponible', function () {
    $fake = Mockery::mock(BcpRateFetcher::class);
    $fake->shouldReceive('fetchLatest')->andReturn(['date' => '2026-06-25', 'rate' => 7100.00]);
    app()->instance(BcpRateFetcher::class, $fake);

    $this->artisan('exchange-rates:poll')->assertSuccessful();

    expect((float) ExchangeRate::where('date', '2026-06-25')->first()->rate)->toBe(7100.0);
});

it('no falla si el BCP no devuelve datos', function () {
    $fake = Mockery::mock(BcpRateFetcher::class);
    $fake->shouldReceive('fetchLatest')->andReturnNull();
    app()->instance(BcpRateFetcher::class, $fake);

    $this->artisan('exchange-rates:poll')->assertSuccessful();
    expect(ExchangeRate::count())->toBe(0);
});
