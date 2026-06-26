<?php

use CarlVallory\KrayinNetValue\Services\Bcp\BcpHttpRateFetcher;
use CarlVallory\KrayinNetValue\Services\Bcp\BcpRateFetcher;
use Illuminate\Support\Facades\Http;

it('parsea el año desde el HTML del BCP a un mapa fecha→tasa', function () {
    $html = file_get_contents(base_path('tests/Fixtures/bcp_anual_2026.html'));
    Http::fake(['*' => Http::response($html, 200)]);

    $fetcher = app(BcpRateFetcher::class);
    expect($fetcher)->toBeInstanceOf(BcpHttpRateFetcher::class);

    $rates = $fetcher->fetchYear(2026);

    // Días con cotización presentes y parseados; "ND" excluidos. Valores reales del fixture (VENTA 2026).
    expect($rates)->toBeArray()->not->toBeEmpty();
    expect($rates['2026-01-02'])->toBe(6738.95);
    expect($rates['2026-04-01'])->toBe(6489.25);
    expect($rates)->not->toHaveKey('2026-01-01'); // ND en el fixture

    foreach ($rates as $date => $rate) {
        expect($date)->toMatch('/^\d{4}-\d{2}-\d{2}$/');
        expect($rate)->toBeFloat();
    }
});

it('fetchLatest devuelve la última cotización disponible del año en curso', function () {
    $html = file_get_contents(base_path('tests/Fixtures/bcp_anual_2026.html'));
    Http::fake(['*' => Http::response($html, 200)]);

    $latest = app(BcpRateFetcher::class)->fetchLatest();

    expect($latest)->toBeArray()->toHaveKeys(['date', 'rate']);
    expect($latest['date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    expect($latest['rate'])->toBeFloat();
});
