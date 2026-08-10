<?php

use CarlVallory\KrayinTicketSales\Support\SpanishDateParser;

beforeEach(function () {
    $this->parser = new SpanishDateParser();
});

test('parsea el formato real de FooEvents', function () {
    expect($this->parser->parse('agosto 7, 2026')->format('Y-m-d'))->toBe('2026-08-07');
    expect($this->parser->parse('enero 1, 2027')->format('Y-m-d'))->toBe('2027-01-01');
    expect($this->parser->parse('diciembre 31, 2026')->format('Y-m-d'))->toBe('2026-12-31');
});

test('acepta septiembre y setiembre', function () {
    expect($this->parser->parse('septiembre 15, 2026')->format('Y-m-d'))->toBe('2026-09-15');
    expect($this->parser->parse('setiembre 15, 2026')->format('Y-m-d'))->toBe('2026-09-15');
});

test('tolera mayúsculas, espacios de más y espacio duro', function () {
    expect($this->parser->parse('  Agosto 7, 2026  ')->format('Y-m-d'))->toBe('2026-08-07');
    expect($this->parser->parse("agosto\u{a0}7, 2026")->format('Y-m-d'))->toBe('2026-08-07');
    expect($this->parser->parse('AGOSTO 7,2026')->format('Y-m-d'))->toBe('2026-08-07');
});

test('parsea todas las cadenas de fecha reales de la base', function () {
    // Guard contra un mes escrito de una forma que no conocemos. Los fixtures
    // son el volcado real de los 18 productos dateslot publicados; si al
    // refrescarlos aparece una fecha que no parsea, este test la delata.
    $strings = [];

    foreach (glob(__DIR__ . '/../../Fixtures/fooevents/bookings_*.json') as $path) {
        $decoded = json_decode(file_get_contents($path), true);

        if (! is_array($decoded)) {
            continue;
        }

        foreach ($decoded as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            if (isset($slot['add_date']) && is_array($slot['add_date'])) {
                foreach ($slot['add_date'] as $entry) {
                    if (is_array($entry) && isset($entry['date'])) {
                        $strings[] = $entry['date'];
                    }
                }

                continue;
            }

            foreach ($slot as $key => $value) {
                if (is_string($value) && str_ends_with($key, '_add_date')) {
                    $strings[] = $value;
                }
            }
        }
    }

    expect($strings)->not->toBeEmpty();

    $parser = new SpanishDateParser();

    foreach (array_unique($strings) as $raw) {
        expect($parser->parse($raw))->not->toBeNull("no parseó: {$raw}");
    }
});

test('devuelve null sin lanzar ante entrada inválida', function () {
    expect($this->parser->parse(null))->toBeNull();
    expect($this->parser->parse(''))->toBeNull();
    expect($this->parser->parse('basura'))->toBeNull();
    expect($this->parser->parse('smarch 7, 2026'))->toBeNull();
    expect($this->parser->parse('2026-08-07'))->toBeNull();
    expect($this->parser->parse('febrero 30, 2026'))->toBeNull();
});
