<?php

use CarlVallory\KrayinTicketSales\Support\BookingsOptionsParser;
use CarlVallory\KrayinTicketSales\Support\SpanishDateParser;

beforeEach(function () {
    $this->parser = new BookingsOptionsParser(new SpanishDateParser());
});

test('forma A: add_date anidado', function () {
    // Producto 192862 real, recortado.
    $json = json_encode([
        'ouxdnafhvfajelesmudp' => [
            'label'          => 'Entrada general',
            'formatted_time' => '(10:30)',
            'add_date'       => [
                'bhwytbybxprriwmnyrje' => ['date' => 'agosto 4, 2026', 'stock' => '27'],
                'dtlyptyudclrczpuazvb' => ['date' => 'agosto 7, 2026', 'stock' => '0'],
            ],
        ],
    ]);

    expect($this->parser->parse($json))->toBe([
        ['slot' => 'Entrada general (10:30)', 'date' => '2026-08-04', 'stock' => 27],
        ['slot' => 'Entrada general (10:30)', 'date' => '2026-08-07', 'stock' => 0],
    ]);
});

test('forma B: claves planas con sufijo _add_date', function () {
    // Producto 194099 real, recortado. Sin formatted_time: se compone de hour/minute.
    $json = json_encode([
        'fnblmbjmeyvmozuctqep' => [
            'label'                         => 'Entrada general',
            'hour'                          => '19',
            'minute'                        => '00',
            'period'                        => '',
            'add_time'                      => 'enabled',
            'pkkamubfpsgfajqiceka_add_date' => 'agosto 1, 2026',
            'pkkamubfpsgfajqiceka_stock'    => '3',
            'hcilttetcjeihjxtouov_add_date' => 'agosto 15, 2026',
            'hcilttetcjeihjxtouov_stock'    => '30',
        ],
    ]);

    expect($this->parser->parse($json))->toBe([
        ['slot' => 'Entrada general (19:00)', 'date' => '2026-08-01', 'stock' => 3],
        ['slot' => 'Entrada general (19:00)', 'date' => '2026-08-15', 'stock' => 30],
    ]);
});

test('el label puede traer su propio horario, distinto al del slot', function () {
    // Producto 192637 real: el ticket guarda "BioEstanque (16:00) (17:00)".
    $json = json_encode([
        'k' => [
            'label'          => 'BioEstanque (16:00)',
            'formatted_time' => '(17:00)',
            'add_date'       => ['d' => ['date' => 'agosto 7, 2026', 'stock' => '18']],
        ],
    ]);

    expect($this->parser->parse($json)[0]['slot'])->toBe('BioEstanque (16:00) (17:00)');
});

test('stock viene como string o como int', function () {
    $json = json_encode([
        'k' => [
            'label'          => 'X',
            'formatted_time' => '(10:00)',
            'add_date'       => [
                'a' => ['date' => 'agosto 13, 2026', 'stock' => '13'],
                'b' => ['date' => 'agosto 14, 2026', 'stock' => 14],
            ],
        ],
    ]);

    expect(array_column($this->parser->parse($json), 'stock'))->toBe([13, 14]);
});

test('devuelve lista vacía sin lanzar ante metadatos degenerados', function () {
    expect($this->parser->parse(null))->toBe([]);
    expect($this->parser->parse(''))->toBe([]);
    expect($this->parser->parse('[]'))->toBe([]);
    expect($this->parser->parse('{}'))->toBe([]);
    expect($this->parser->parse('null'))->toBe([]);
    expect($this->parser->parse('{roto'))->toBe([]);
    expect($this->parser->parse(json_encode(['k' => 'no es un array'])))->toBe([]);
});

test('descarta fechas ilegibles pero conserva el resto del slot', function () {
    $json = json_encode([
        'k' => [
            'label'          => 'X',
            'formatted_time' => '(10:00)',
            'add_date'       => [
                'a' => ['date' => 'basura', 'stock' => '5'],
                'b' => ['date' => 'agosto 7, 2026', 'stock' => '9'],
            ],
        ],
    ]);

    expect($this->parser->parse($json))->toBe([
        ['slot' => 'X (10:00)', 'date' => '2026-08-07', 'stock' => 9],
    ]);
});

test('un slot sin horario usa solo el label', function () {
    $json = json_encode([
        'k' => [
            'label'    => 'Entrada única',
            'add_date' => ['a' => ['date' => 'agosto 7, 2026', 'stock' => '5']],
        ],
    ]);

    expect($this->parser->parse($json)[0]['slot'])->toBe('Entrada única');
});

/**
 * Devuelve las funciones de una fecha, por producto, a partir de los fixtures
 * reales de los 18 productos dateslot publicados.
 *
 * @return array<string, array<int, array{slot: string, date: string, stock: int}>>
 */
function funcionesRealesPorProducto(BookingsOptionsParser $parser, string $fecha, bool $soloFormaA = false): array
{
    $porProducto = [];

    foreach (glob(__DIR__ . '/../../Fixtures/fooevents/bookings_*.json') as $path) {
        $productId = basename($path, '.json');
        $productId = substr($productId, strlen('bookings_'));
        $raw       = file_get_contents($path);

        if ($soloFormaA) {
            // Simula un parser que solo entiende la forma A: descarta los
            // productos cuya meta no trae `add_date`.
            $decoded = json_decode($raw, true);
            $first   = is_array($decoded) ? (reset($decoded) ?: null) : null;

            if (! is_array($first) || ! isset($first['add_date'])) {
                continue;
            }
        }

        $delDia = array_values(array_filter(
            $parser->parse($raw),
            fn (array $f) => $f['date'] === $fecha
        ));

        if ($delDia !== []) {
            $porProducto[$productId] = $delDia;
        }
    }

    return $porProducto;
}

test('la programación real del 2026-08-07 son 11 funciones sobre 7 shows', function () {
    // Prueba de aceptación del spec §13, contra el volcado real de la base.
    $porProducto = funcionesRealesPorProducto($this->parser, '2026-08-07');

    $funciones = array_merge(...array_values($porProducto));

    expect($porProducto)->toHaveCount(7);
    expect($funciones)->toHaveCount(11);

    expect(array_keys($porProducto))->toEqualCanonicalizing([
        '192637', '192862', '193817', '194055', '194099', '194154', '194339',
    ]);

    // Los cupos del spec §13: 192637 es el único con remanente distinto de 0.
    $cupos = array_column($porProducto['192637'], 'stock', 'slot');
    ksort($cupos);

    expect($cupos)->toBe([
        'BioEstanque (16:00) (17:00)' => 18,
        'BioEstanque (17:00) (18:00)' => 20,
        'BioEstanque (18:00) (19:00)' => 18,
    ]);
});

test('ignorar la forma B perdería 4 de los 7 shows del día', function () {
    // Este es el fallo que el parser dual existe para evitar. Con datos reales:
    // 11 funciones sobre 7 shows se degradan a 7 funciones sobre 3 shows.
    $completo = funcionesRealesPorProducto($this->parser, '2026-08-07');
    $soloA    = funcionesRealesPorProducto($this->parser, '2026-08-07', soloFormaA: true);

    expect($soloA)->toHaveCount(3);
    expect(array_merge(...array_values($soloA)))->toHaveCount(7);

    $perdidos = array_diff(array_keys($completo), array_keys($soloA));

    expect(array_values($perdidos))->toEqualCanonicalizing(['194055', '194099', '194154', '194339']);
});
