<?php

use CarlVallory\KrayinNetValue\Models\ExchangeRate;
use CarlVallory\KrayinNetValue\Services\ExchangeRateResolver;

beforeEach(function () {
    ExchangeRate::query()->delete();
    ExchangeRate::create(['date' => '2026-01-02', 'rate' => 6719.39, 'source' => 'bcp']);
    ExchangeRate::create(['date' => '2026-01-05', 'rate' => 6800.00, 'source' => 'bcp']);
});

it('devuelve la tasa exacta si existe', function () {
    expect(app(ExchangeRateResolver::class)->rateForDate('2026-01-05'))->toBe(6800.0);
});

it('cae a la última fecha previa disponible (finde/feriado)', function () {
    // 2026-01-03 (sábado) y 2026-01-04 (domingo) no existen → usa 2026-01-02
    expect(app(ExchangeRateResolver::class)->rateForDate('2026-01-04'))->toBe(6719.39);
});

it('devuelve null si no hay tasa hasta esa fecha', function () {
    expect(app(ExchangeRateResolver::class)->rateForDate('2025-12-31'))->toBeNull();
});
