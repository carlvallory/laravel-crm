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
        'categorias'        => ['talleres'],
    ], $sobre);
}

/** La misma función, pero categorizada como domo. */
function funcionDelDomo(array $sobre = []): array
{
    return funcionDePantalla(array_merge(['categorias' => ['san-cosmos']], $sobre));
}

/** El criterio, tal como lo entrega `CriterioDeSanCosmos::categorias()`. */
function criterioDomo(): array
{
    return ['san-cosmos', 'entrada-sancosmos'];
}

test('las funciones de una categoría de San Cosmos van al panel izquierdo', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(['hora' => '15:30', 'entradas_vendidas' => 25]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([['hora' => '15:30', 'entradas' => 25]]);
    expect($r['especiales'])->toBe([]);
});

test('las que no están en la lista van a especiales con su nombre', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['show_nombre' => 'Taller de robots', 'categorias' => ['talleres']]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'][0]['show'])->toBe('Taller de robots');
});

test('alcanza con que UNA de las categorías de la función esté en la lista', function () {
    // Los productos de WooCommerce suelen llevar varias categorías. Exigir que
    // todas coincidan dejaría afuera cualquier show del domo que además esté
    // etiquetado como "eventos" — que hoy es el caso de los 13.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['categorias' => ['eventos', 'san-cosmos', 'destacados']]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toHaveCount(1);
    expect($r['especiales'])->toBe([]);
});

test('categorias en null va a especiales', function () {
    // El estado del día del deploy: el servicio todavía no manda el campo.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['categorias' => null]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'])->toHaveCount(1);
});

test('categorias en lista vacía va a especiales', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['categorias' => []]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'])->toHaveCount(1);
});

test('con el criterio vacío todo va a especiales y nada al panel izquierdo', function () {
    // Es lo que pasa si la fila de core_config quedó con basura. La pantalla se
    // degrada a nombres a la derecha, sin apagar nada.
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Taller']),
    ], []);

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'])->toHaveCount(2);
});

test('la comparación de categorías ignora mayúsculas y espacios', function () {
    // El criterio llega normalizado desde CriterioDeSanCosmos; lo que puede
    // venir sucio es el lado de la función, que sale de WordPress.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['categorias' => ['  San-Cosmos ']]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toHaveCount(1);
});

test('dos productos distintos del domo a la misma hora se fusionan en una tarjeta', function () {
    // El corazón del cambio. El domo es uno: dos funciones a las 16:30 son la
    // misma función vendida bajo dos etiquetas, y sin nombres dos tarjetas
    // "16:30" en la TV no significan nada. Sumar no dobla el conteo porque el
    // servicio indexa por producto + slot.
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(['producto_id' => 1, 'show_nombre' => 'Marte', 'hora' => '16:30', 'entradas_vendidas' => 2]),
        funcionDelDomo(['producto_id' => 2, 'show_nombre' => 'Historias', 'hora' => '16:30', 'entradas_vendidas' => 20]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([['hora' => '16:30', 'entradas' => 22]]);
});

test('la misma hora en dos especiales distintos NO se fusiona', function () {
    // La contracara: a la derecha los nombres se ven, y dos actividades
    // especiales a la misma hora son dos cosas distintas que pasan a la vez.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '16:30', 'entradas_vendidas' => 2]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '16:30', 'entradas_vendidas' => 20]),
    ], criterioDomo());

    expect($r['especiales'])->toHaveCount(2);
});

test('dos filas del mismo producto del domo a la misma hora se suman', function () {
    // El caso del slot renombrado en WordPress, que ya existía antes de este
    // cambio y sigue valiendo.
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(['hora' => '16:30', 'entradas_vendidas' => 2]),
        funcionDelDomo(['hora' => '16:30', 'entradas_vendidas' => 20]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([['hora' => '16:30', 'entradas' => 22]]);
});

test('las funciones del panel izquierdo se ordenan por hora', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(['producto_id' => 1, 'hora' => '17:30', 'entradas_vendidas' => 1]),
        funcionDelDomo(['producto_id' => 2, 'hora' => '09:00', 'entradas_vendidas' => 2]),
        funcionDelDomo(['producto_id' => 3, 'hora' => '13:15', 'entradas_vendidas' => 3]),
    ], criterioDomo());

    expect(array_column($r['sanCosmos']['funciones'], 'hora'))->toBe(['09:00', '13:15', '17:30']);
});

test('las del domo sin hora no se fusionan entre sí y van al final', function () {
    // Dos funciones sin horario no tienen por qué ser la misma, y una sin
    // horario encabezando el panel es donde nadie la espera.
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(['producto_id' => 1, 'hora' => null, 'entradas_vendidas' => 3]),
        funcionDelDomo(['producto_id' => 2, 'hora' => null, 'entradas_vendidas' => 4]),
        funcionDelDomo(['producto_id' => 3, 'hora' => '10:00', 'entradas_vendidas' => 5]),
    ], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([
        ['hora' => '10:00', 'entradas' => 5],
        ['hora' => null, 'entradas' => 3],
        ['hora' => null, 'entradas' => 4],
    ]);
});

test('el panel izquierdo no expone el nombre de ningún show', function () {
    // Es el pedido, fijado en la estructura: si mañana alguien agrega la clave,
    // este test lo frena antes de que llegue al Blade.
    $r = ProgramacionDePantalla::armar([
        funcionDelDomo(['show_nombre' => 'Misterios de tu Cerebro']),
    ], criterioDomo());

    foreach ($r['sanCosmos']['funciones'] as $funcion) {
        expect(array_keys($funcion))->toBe(['hora', 'entradas']);
    }

    expect(json_encode($r['sanCosmos']))->not->toContain('Misterios');
});

test('especiales queda ordenado por entradas, de mayor a menor', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'entradas_vendidas' => 3]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'entradas_vendidas' => 30]),
    ], criterioDomo());

    expect(array_column($r['especiales'], 'show'))->toBe(['Robots', 'Aves']);
});

test('a igual entradas, en especiales gana el que tiene más funciones', function () {
    // El de una sola función va primero en la entrada a propósito: con un orden
    // estable, no desempatar lo dejaría ganando.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 12]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '08:30', 'entradas_vendidas' => 5]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '09:30', 'entradas_vendidas' => 7]),
    ], criterioDomo());

    expect(array_column($r['especiales'], 'show'))->toBe(['Robots', 'Aves']);
});

test('con todos los especiales en cero gana el de más funciones', function () {
    // Cada mañana, antes de la primera venta, todos están en cero: el empate es
    // la regla, no el caso raro.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 0]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '08:30', 'entradas_vendidas' => 0]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '09:30', 'entradas_vendidas' => 0]),
    ], criterioDomo());

    expect(array_column($r['especiales'], 'show'))->toBe(['Robots', 'Aves']);
});

test('a igual entradas y funciones, en especiales desempata el nombre', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Zorros', 'entradas_vendidas' => 0]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Aves', 'entradas_vendidas' => 0]),
    ], criterioDomo());

    expect(array_column($r['especiales'], 'show'))->toBe(['Aves', 'Zorros']);
});

test('el desempate por funciones cuenta horarios distintos, no filas', function () {
    // Dos filas a la misma hora son UNA función en la tarjeta. Contarlas como
    // dos dejaría ganando a un show con una sola función y una fila duplicada.
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 5]),
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 0]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '11:00', 'entradas_vendidas' => 3]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Robots', 'hora' => '12:00', 'entradas_vendidas' => 2]),
    ], criterioDomo());

    expect(array_column($r['especiales'], 'show'))->toBe(['Robots', 'Aves']);
});

test('agrupa especiales por producto, no por nombre', function () {
    $r = ProgramacionDePantalla::armar([
        funcionDePantalla(['producto_id' => 1, 'show_nombre' => 'Igual', 'hora' => '10:00', 'entradas_vendidas' => 1]),
        funcionDePantalla(['producto_id' => 2, 'show_nombre' => 'Igual', 'hora' => '11:00', 'entradas_vendidas' => 1]),
    ], criterioDomo());

    expect($r['especiales'])->toHaveCount(2);
});

test('sin funciones los dos paneles quedan vacíos', function () {
    $r = ProgramacionDePantalla::armar([], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'])->toBe([]);
});

test('un día de solo domo deja especiales vacío', function () {
    $r = ProgramacionDePantalla::armar([funcionDelDomo()], criterioDomo());

    expect($r['especiales'])->toBe([]);
    expect($r['sanCosmos']['funciones'])->toHaveCount(1);
});

test('un día sin domo deja el panel izquierdo sin funciones', function () {
    $r = ProgramacionDePantalla::armar([funcionDePantalla()], criterioDomo());

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'])->toHaveCount(1);
});

test('fusionar no cambia el total de entradas del día', function () {
    // El invariante que protege contra doblar el conteo al cruzar productos.
    $funciones = [
        funcionDelDomo(['producto_id' => 1, 'hora' => '16:30', 'entradas_vendidas' => 2]),
        funcionDelDomo(['producto_id' => 2, 'hora' => '16:30', 'entradas_vendidas' => 20]),
        funcionDelDomo(['producto_id' => 2, 'hora' => '17:30', 'entradas_vendidas' => 5]),
        funcionDePantalla(['producto_id' => 3, 'hora' => '11:00', 'entradas_vendidas' => 7]),
    ];

    $r = ProgramacionDePantalla::armar($funciones, criterioDomo());

    $enPantalla = array_sum(array_column($r['sanCosmos']['funciones'], 'entradas'))
        + array_sum(array_column($r['especiales'], 'entradas'));

    expect($enPantalla)->toBe(34);
});


test('un nombre que entra en 23 caracteres se muestra entero', function () {
    expect(mb_strlen('Entradas al Bioestanque'))->toBe(23);

    expect(ProgramacionDePantalla::nombreCorto('Entradas al Bioestanque'))
        ->toBe('Entradas al Bioestanque');
});

test('un nombre de 24 caracteres ya se corta', function () {
    // El límite de arriba y este van juntos: con uno solo, correr el tope un
    // lugar para cualquiera de los dos lados pasaría igual.
    expect(mb_strlen('Entradas al Bioestanques'))->toBe(24);

    expect(ProgramacionDePantalla::nombreCorto('Entradas al Bioestanques'))
        ->toBe('Entradas al Bioestan...');
});

test('el nombre cortado nunca pasa de 23 caracteres, puntos incluidos', function () {
    $corto = ProgramacionDePantalla::nombreCorto('Entrada al Gran Bioestanque');

    expect($corto)->toBe('Entrada al Gran Bioe...');
    expect(mb_strlen($corto))->toBe(23);
});

test('el corte cuenta caracteres y no bytes', function () {
    // Con `substr` a secas los acentos ocupan dos, así que el nombre saldría
    // más corto de lo debido y con la última letra partida al medio.
    $corto = ProgramacionDePantalla::nombreCorto('Función de títeres en el jardín');

    expect($corto)->toBe('Función de títeres e...');
    expect(mb_strlen($corto))->toBe(23);
});

test('no queda un espacio colgando antes de los puntos', function () {
    // El corte cae justo en un espacio: sin `rtrim` saldría «las 4 ...».
    expect(mb_substr('Entradas para las 4 funciones', 19, 1))->toBe(' ');

    expect(ProgramacionDePantalla::nombreCorto('Entradas para las 4 funciones'))
        ->toBe('Entradas para las 4...');
});


test('un categorias que no es lista ni null va a especiales, sin iterar basura', function () {
    // No llega por el camino del sync —el cliente garantiza array|null— pero la
    // clase es pura y recibe lo que le pasen. Sin el `is_array`, el `foreach`
    // recorrería un string y PHP tiraría un warning; con él, la función cae a la
    // derecha y no pasa nada. Se afirma el warning y no solo el resultado,
    // porque el resultado es el mismo por los dos caminos.
    $r = null;

    set_error_handler(function (int $nivel, string $mensaje) {
        throw new RuntimeException("PHP avisó: {$mensaje}");
    });

    try {
        $r = ProgramacionDePantalla::armar([
            funcionDePantalla(['categorias' => 'san-cosmos']),
        ], criterioDomo());
    } finally {
        restore_error_handler();
    }

    expect($r['sanCosmos']['funciones'])->toBe([]);
    expect($r['especiales'])->toHaveCount(1);
});
