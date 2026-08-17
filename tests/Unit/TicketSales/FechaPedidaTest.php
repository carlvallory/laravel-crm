<?php

use CarlVallory\KrayinTicketSales\Support\FechaPedida;

/**
 * La fecha viaja en la URL, así que entra cualquier cosa. Estos tests fijan qué
 * se acepta, qué se corrige en silencio y qué se conserva para poder explicarlo.
 *
 * «Hoy» es un argumento y no el reloj: la ventana se corre sola cada día, y un
 * test atado a `now()` empezaría a fallar solo por pasar la medianoche.
 */
const TS_HOY = '2026-08-17';

test('sin parámetro se muestra hoy', function () {
    $pedida = FechaPedida::resolver(null, TS_HOY, 7);

    expect($pedida->fecha())->toBe(TS_HOY);
    expect($pedida->esHoy())->toBeTrue();
    expect($pedida->fueraDeVentana())->toBeFalse();
});

test('una fecha dentro de la ventana se respeta', function () {
    $pedida = FechaPedida::resolver('2026-08-12', TS_HOY, 7);

    expect($pedida->fecha())->toBe('2026-08-12');
    expect($pedida->esHoy())->toBeFalse();
    expect($pedida->fueraDeVentana())->toBeFalse();
});

test('el borde de abajo de la ventana todavía entra', function () {
    // `purgar()` borra `fecha < fecha_sincronizada - retention_days`, así que el
    // día del corte sobrevive. La ventana lo tiene que incluir o el picker
    // escondería un día que sí está guardado.
    $pedida = FechaPedida::resolver('2026-08-10', TS_HOY, 7);

    expect($pedida->fecha())->toBe('2026-08-10');
    expect($pedida->fueraDeVentana())->toBeFalse();
});

test('un día antes del borde ya queda fuera', function () {
    // El par del de arriba: con uno solo, correr el borde un lugar para
    // cualquiera de los dos lados pasaría igual.
    $pedida = FechaPedida::resolver('2026-08-09', TS_HOY, 7);

    expect($pedida->fecha())->toBe('2026-08-09');
    expect($pedida->fueraDeVentana())->toBeTrue();
    expect($pedida->esFutura())->toBeFalse();
});

test('la fecha que quedó fuera se conserva, no se reemplaza por hoy', function () {
    // Es la diferencia entre «ese día no hubo funciones» y «ese día ya no se
    // guarda». Si la vista recibiera hoy, no podría decir la segunda.
    expect(FechaPedida::resolver('2026-01-01', TS_HOY, 7)->fecha())->toBe('2026-01-01');
});

test('hoy pedido a mano cuenta como hoy', function () {
    // Importa: `esHoy()` es lo que decide si corre la guarda de OTRO_DIA. Con
    // `?fecha=<hoy>` en la URL el tablero tiene que comportarse igual que sin
    // parámetro, o el enlace de la pantalla apagaría la guarda sin querer.
    $pedida = FechaPedida::resolver(TS_HOY, TS_HOY, 7);

    expect($pedida->esHoy())->toBeTrue();
    expect($pedida->fueraDeVentana())->toBeFalse();
});

test('una fecha futura queda fuera de la ventana', function () {
    $pedida = FechaPedida::resolver('2026-08-18', TS_HOY, 7);

    expect($pedida->fecha())->toBe('2026-08-18');
    expect($pedida->fueraDeVentana())->toBeTrue();
    expect($pedida->esFutura())->toBeTrue();
});

test('un formato que no es una fecha cae en hoy', function () {
    foreach (['lunes', '', '17/08/2026', '2026-13-01', 'null', '2026-08-17T10:00:00'] as $basura) {
        expect(FechaPedida::resolver($basura, TS_HOY, 7)->fecha())
            ->toBe(TS_HOY, "«{$basura}» tendría que caer en hoy");
    }
});

test('el 31 de febrero no se rueda al 3 de marzo', function () {
    // `createFromFormat` acepta 2026-02-31 y devuelve el 3 de marzo sin
    // quejarse. Sin comparar contra el reformateo, el tablero mostraría un día
    // que nadie pidió y el picker quedaría marcando otra cosa.
    expect(FechaPedida::resolver('2026-02-31', TS_HOY, 7)->fecha())->toBe(TS_HOY);
});

test('un largo distinto no se cuela aunque empiece bien', function () {
    expect(FechaPedida::resolver('2026-8-1', TS_HOY, 7)->fecha())->toBe(TS_HOY);
});

test('la fecha mínima es hoy menos la retención', function () {
    expect(FechaPedida::resolver(null, TS_HOY, 7)->minima())->toBe('2026-08-10');
    expect(FechaPedida::resolver(null, TS_HOY, 30)->minima())->toBe('2026-07-18');
});
