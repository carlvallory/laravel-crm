<?php

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Models\TicketSalesSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

test('una función se persiste y se lee con los tipos del contrato', function () {
    TicketSalesSnapshot::create([
        'fecha'                => '2026-08-07',
        'producto_id'          => 192637,
        'show_nombre'          => 'Entrada Bioestanque',
        'slot'                 => 'BioEstanque (16:00) (17:00)',
        'hora'                 => '17:00',
        'entradas_vendidas'    => 2,
        'entradas_reagendadas' => 0,
        'cupos_habilitados'    => 18,
        'recaudacion_neta'     => 63636,
        'recaudacion_bruta'    => 70000,
    ]);

    $fila = TicketSalesSnapshot::where('producto_id', 192637)->first();

    expect($fila->entradas_vendidas)->toBe(2);
    expect($fila->slot)->toBe('BioEstanque (16:00) (17:00)');
    expect($fila->recaudacion_neta)->toBe(63636);
    expect($fila->recaudacion_bruta)->toBe(70000);
});

test('los montos y los conteos son enteros porque el modelo lo garantiza, no porque el driver los devuelva así', function () {
    // Este test existe porque el de arriba no alcanza: persiste y vuelve a leer,
    // y PDO ya devuelve entero nativo para una columna INT — con cast o sin él.
    // O sea que `toBe(63636)` pasa igual si a $casts le falta el campo, y el
    // agujero solo se ve el día que un valor llega como cadena desde PHP.
    // Acá no hay ida a la base: se mide lo que el modelo expone.
    $fila = new TicketSalesSnapshot([
        'fecha'                => '2026-08-07',
        'producto_id'          => '192637',
        'show_nombre'          => 'Entrada Bioestanque',
        'slot'                 => 'BioEstanque (16:00) (17:00)',
        'hora'                 => '17:00',
        'entradas_vendidas'    => '2',
        'entradas_reagendadas' => '0',
        'cupos_habilitados'    => '18',
        'recaudacion_neta'     => '63636',
        'recaudacion_bruta'    => '70000',
    ]);

    expect($fila->producto_id)->toBe(192637);
    expect($fila->entradas_vendidas)->toBe(2);
    expect($fila->entradas_reagendadas)->toBe(0);
    expect($fila->cupos_habilitados)->toBe(18);
    expect($fila->recaudacion_neta)->toBe(63636);
    expect($fila->recaudacion_bruta)->toBe(70000);
});

test('cupos_habilitados acepta null: la función existe por tickets pero no está programada', function () {
    $fila = TicketSalesSnapshot::create([
        'fecha'                => '2026-08-07',
        'producto_id'          => 1,
        'show_nombre'          => 'X',
        'slot'                 => 'Y',
        'hora'                 => null,
        'entradas_vendidas'    => 1,
        'entradas_reagendadas' => 0,
        'cupos_habilitados'    => null,
        'recaudacion_neta'     => 0,
        'recaudacion_bruta'    => 0,
    ]);

    expect($fila->fresh()->cupos_habilitados)->toBeNull();
    expect($fila->fresh()->hora)->toBeNull();
});

test('la cabecera guarda los avisos como estructura, no como texto', function () {
    TicketSalesSync::create([
        'fecha'       => '2026-08-07',
        'generado_en' => '2026-08-07 17:30:00',
        'avisos'      => [
            ['tipo' => 'linea_faltante', 'detalle' => 'Par 500:192637 sin línea.'],
        ],
        'synced_at'   => now(),
    ]);

    $sync = TicketSalesSync::where('fecha', '2026-08-07')->first();

    expect($sync->avisos)->toBeArray();
    expect($sync->avisos[0]['tipo'])->toBe('linea_faltante');
});

test('la cabecera admite avisos vacíos, que es el caso normal', function () {
    TicketSalesSync::create([
        'fecha'       => '2026-08-07',
        'generado_en' => '2026-08-07 17:30:00',
        'avisos'      => [],
        'synced_at'   => now(),
    ]);

    expect(TicketSalesSync::where('fecha', '2026-08-07')->first()->avisos)->toBe([]);
});

test('no puede haber dos cabeceras para la misma fecha', function () {
    TicketSalesSync::create([
        'fecha' => '2026-08-07', 'generado_en' => now(), 'avisos' => [], 'synced_at' => now(),
    ]);

    expect(fn () => TicketSalesSync::create([
        'fecha' => '2026-08-07', 'generado_en' => now(), 'avisos' => [], 'synced_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('categorias se guarda como lista y vuelve como array', function () {
    $fila = TicketSalesSnapshot::create([
        'fecha'       => '2026-08-07',
        'producto_id' => 1,
        'show_nombre' => 'Marte',
        'slot'        => 'Domo (15:30)',
        'hora'        => '15:30',
        'categorias'  => ['san-cosmos', 'eventos'],
    ]);

    expect($fila->fresh()->categorias)->toBe(['san-cosmos', 'eventos']);
});

test('categorias en null y en lista vacía no son lo mismo', function () {
    // `null` es "no sé" —servicio viejo o campo malformado— y `[]` es "este
    // producto no tiene categorías". Los dos van al panel derecho, pero
    // confundirlos borra la única señal de que el servicio no está mandando
    // el campo.
    $sinDato = TicketSalesSnapshot::create([
        'fecha' => '2026-08-07', 'producto_id' => 1, 'show_nombre' => 'A',
        'slot'  => 'X', 'hora' => '10:00', 'categorias' => null,
    ]);

    $sinCategorias = TicketSalesSnapshot::create([
        'fecha' => '2026-08-07', 'producto_id' => 2, 'show_nombre' => 'B',
        'slot'  => 'Y', 'hora' => '11:00', 'categorias' => [],
    ]);

    expect($sinDato->fresh()->categorias)->toBeNull();
    expect($sinCategorias->fresh()->categorias)->toBe([]);
});
