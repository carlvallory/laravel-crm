<?php

use CarlVallory\KrayinTicketSales\Support\ProgramacionDePantalla;

/**
 * Las funciones entran como arrays y no como modelos: la clase es pura y los
 * tests Unit corren sin app, así que no hay resolver de Eloquent. Que también
 * funcione con los modelos de verdad lo fija el test Feature de la ruta, que va
 * contra la base.
 */
function funcionDePantalla(array $sobre = []): array
{
    return array_merge([
        'producto_id'       => 192637,
        'show_nombre'       => 'Entrada Bioestanque',
        'hora'              => '16:00',
        'entradas_vendidas' => 2,
    ], $sobre);
}

test('el show con más entradas vendidas va al panel destacado', function () {
    $resultado = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 12]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '08:30', 'entradas_vendidas' => 30]),
    ]);

    expect($resultado['destacado']['show'])->toBe('San Cosmos');
    expect($resultado['destacado']['entradas'])->toBe(30);
});

test('a igual cantidad de entradas gana el que tiene más funciones', function () {
    // El de una sola función va primero en la entrada a propósito: con un orden
    // estable, no desempatar lo dejaría ganando.
    $resultado = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 12]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '08:30', 'entradas_vendidas' => 5]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '09:30', 'entradas_vendidas' => 7]),
    ]);

    expect($resultado['destacado']['show'])->toBe('San Cosmos');
});

test('con todos los shows en cero gana el de más funciones', function () {
    // El caso de cada mañana, antes de la primera venta: el empate es la regla.
    $resultado = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 0]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '08:30', 'entradas_vendidas' => 0]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '09:30', 'entradas_vendidas' => 0]),
    ]);

    expect($resultado['destacado']['show'])->toBe('San Cosmos');
    expect($resultado['destacado']['entradas'])->toBe(0);
});

test('a igual entradas y misma cantidad de funciones desempata el nombre', function () {
    // «Zeta» va primero en la entrada para que el orden alfabético no coincida
    // con el de llegada: si coincidiera, el test pasaría sin criterio ninguno.
    $resultado = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Zeta', 'hora' => '10:00', 'entradas_vendidas' => 12]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Alfa', 'hora' => '11:00', 'entradas_vendidas' => 12]),
    ]);

    expect($resultado['destacado']['show'])->toBe('Alfa');
});

test('las funciones del destacado se ordenan por hora', function () {
    $resultado = ProgramacionDePantalla::armar([
        funcionDePantalla(['hora' => '19:00', 'entradas_vendidas' => 13]),
        funcionDePantalla(['hora' => '08:30', 'entradas_vendidas' => 12]),
        funcionDePantalla(['hora' => '16:00', 'entradas_vendidas' => 21]),
    ]);

    expect(array_column($resultado['destacado']['funciones'], 'hora'))
        ->toBe(['08:30', '16:00', '19:00']);
});

test('el resto queda ordenado por entradas, de mayor a menor', function () {
    $resultado = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 7]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '08:30', 'entradas_vendidas' => 30]),
        funcionDePantalla(['producto_id' => 3, 'show_nombre' => 'Creamundos', 'hora' => '11:00', 'entradas_vendidas' => 12]),
    ]);

    expect(array_column($resultado['resto'], 'show'))->toBe(['Creamundos', 'Aves']);
});

test('un solo show deja el resto vacío', function () {
    $resultado = ProgramacionDePantalla::armar([
        funcionDePantalla(['hora' => '16:00', 'entradas_vendidas' => 2]),
    ]);

    expect($resultado['destacado']['show'])->toBe('Entrada Bioestanque');
    expect($resultado['resto'])->toBe([]);
});

test('sin funciones no hay destacado', function () {
    $resultado = ProgramacionDePantalla::armar([]);

    expect($resultado['destacado'])->toBeNull();
    expect($resultado['resto'])->toBe([]);
});

test('agrupa por producto, no por nombre', function () {
    // Dos productos distintos con el mismo título siguen siendo dos shows: el
    // producto es la identidad, igual que en la llave del servicio.
    $resultado = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'San Cosmos', 'hora' => '10:00', 'entradas_vendidas' => 7]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '11:00', 'entradas_vendidas' => 30]),
    ]);

    expect($resultado['destacado']['producto_id'])->toBe(2);
    expect($resultado['resto'])->toHaveCount(1);
    expect($resultado['resto'][0]['producto_id'])->toBe(1);
});

test('las funciones sin hora van al final', function () {
    // Sin el `hora IS NULL` primero, una función sin horario encabeza el panel.
    $resultado = ProgramacionDePantalla::armar([
        funcionDePantalla(['hora' => null, 'entradas_vendidas' => 4]),
        funcionDePantalla(['hora' => '08:30', 'entradas_vendidas' => 12]),
    ]);

    expect(array_column($resultado['destacado']['funciones'], 'hora'))
        ->toBe(['08:30', null]);
});
