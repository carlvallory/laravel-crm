<?php

use CarlVallory\KrayinNetValue\Models\ExchangeRate;
use CarlVallory\KrayinNetValue\Services\Bcp\BcpRateFetcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;

// Rollback automático: el delete() del beforeEach y los upserts del comando no persisten.
uses(DatabaseTransactions::class);

beforeEach(function () {
    ExchangeRate::query()->delete();

    $fake = Mockery::mock(BcpRateFetcher::class);
    $fake->shouldReceive('fetchYear')->with(2026)->andReturn([
        '2026-01-02' => 6719.39,
        '2026-01-05' => 6800.00,
    ]);
    app()->instance(BcpRateFetcher::class, $fake);
});

it('carga las tasas del año en exchange_rates', function () {
    $this->artisan('exchange-rates:backfill', ['year' => 2026])->assertSuccessful();

    expect(ExchangeRate::count())->toBe(2);
    expect((float) ExchangeRate::where('date', '2026-01-02')->first()->rate)->toBe(6719.39);
});

it('es idempotente (correr dos veces no duplica)', function () {
    $this->artisan('exchange-rates:backfill', ['year' => 2026]);
    $this->artisan('exchange-rates:backfill', ['year' => 2026]);

    expect(ExchangeRate::count())->toBe(2);
});
