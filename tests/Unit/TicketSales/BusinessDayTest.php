<?php

use Carbon\CarbonImmutable;
use CarlVallory\KrayinTicketSales\Support\BusinessDay;

beforeEach(function () {
    $this->day = new BusinessDay('America/Asuncion');
});

test('a media tarde el día es el mismo en UTC y en Asunción', function () {
    $now = CarbonImmutable::create(2026, 8, 7, 20, 30, 0, 'UTC'); // 17:30 en Asunción

    expect($this->day->todayString($now))->toBe('2026-08-07');
});

test('a las 22:00 de Asunción sigue siendo hoy, aunque en UTC ya sea mañana', function () {
    // 01:00 UTC del 8 = 22:00 del 7 en Asunción. Este es el bug que CURDATE() causaría.
    $now = CarbonImmutable::create(2026, 8, 8, 1, 0, 0, 'UTC');

    expect($this->day->todayString($now))->toBe('2026-08-07');
});

test('pasada la medianoche local ya es el día siguiente', function () {
    $now = CarbonImmutable::create(2026, 8, 8, 3, 30, 0, 'UTC'); // 00:30 del 8 en Asunción

    expect($this->day->todayString($now))->toBe('2026-08-08');
});

test('today devuelve medianoche en la zona del museo', function () {
    $now   = CarbonImmutable::create(2026, 8, 8, 1, 0, 0, 'UTC');
    $today = $this->day->today($now);

    expect($today->format('Y-m-d H:i:s'))->toBe('2026-08-07 00:00:00');
    expect($today->timezoneName)->toBe('America/Asuncion');
});

test('sin argumento usa la hora actual', function () {
    expect($this->day->todayString())->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

test('la tzdata del entorno da -3 en agosto', function () {
    // Paraguay abolió el horario de verano en 2024 y quedó fijo en UTC-3.
    // Una tzdata anterior devolvería -4 en agosto y correría el día un hora.
    // Verificado contra la base: post_date vs post_date_gmt = -3 exacto.
    $offset = (new DateTime('2026-08-07 12:00:00', new DateTimeZone('America/Asuncion')))->getOffset();

    expect($offset)->toBe(-10800); // -3 horas en segundos
});
