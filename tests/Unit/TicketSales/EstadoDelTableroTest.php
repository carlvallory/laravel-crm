<?php

use CarlVallory\KrayinTicketSales\Support\EstadoDelTablero;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->estado = new EstadoDelTablero(15);
    $this->ahora  = CarbonImmutable::parse('2026-08-07 17:30:00', 'America/Asuncion');
});

test('snapshot de hoy y fresco es el tablero normal', function () {
    expect($this->estado->decidir(
        '2026-08-07',
        $this->ahora->subMinutes(3),
        '2026-08-07',
        11,
        $this->ahora
    ))->toBe(EstadoDelTablero::NORMAL);
});

test('snapshot de hoy con 15 minutos o más es viejo', function () {
    expect($this->estado->decidir(
        '2026-08-07',
        $this->ahora->subMinutes(15),
        '2026-08-07',
        11,
        $this->ahora
    ))->toBe(EstadoDelTablero::VIEJO);
});

test('a los 14 minutos todavía es normal: el umbral es 15, no "más de 15"', function () {
    expect($this->estado->decidir(
        '2026-08-07',
        $this->ahora->subMinutes(14),
        '2026-08-07',
        11,
        $this->ahora
    ))->toBe(EstadoDelTablero::NORMAL);
});

test('un snapshot de otro día no muestra sus funciones', function () {
    expect($this->estado->decidir(
        '2026-08-06',
        $this->ahora->subMinutes(2),
        '2026-08-07',
        11,
        $this->ahora
    ))->toBe(EstadoDelTablero::OTRO_DIA);
});

test('otro día gana sobre "sin funciones": nunca se muestra como día vacío', function () {
    // El sync viene fallando desde ayer y hoy no hay filas escritas todavía.
    expect($this->estado->decidir(
        '2026-08-06',
        $this->ahora->subDay(),
        '2026-08-07',
        0,
        $this->ahora
    ))->toBe(EstadoDelTablero::OTRO_DIA);
});

test('otro día gana sobre "viejo": el aviso correcto es que el sync falla', function () {
    expect($this->estado->decidir(
        '2026-08-06',
        $this->ahora->subDay(),
        '2026-08-07',
        11,
        $this->ahora
    ))->toBe(EstadoDelTablero::OTRO_DIA);
});

test('sin cabecera es "nunca corrió", no "sin funciones"', function () {
    expect($this->estado->decidir(null, null, '2026-08-07', 0, $this->ahora))
        ->toBe(EstadoDelTablero::NUNCA);
});

test('hoy sincronizado y sin funciones es el día vacío', function () {
    expect($this->estado->decidir(
        '2026-08-07',
        $this->ahora->subMinutes(2),
        '2026-08-07',
        0,
        $this->ahora
    ))->toBe(EstadoDelTablero::SIN_FUNCIONES);
});

test('esViejo es independiente del estado, así la banda también sale en el día vacío', function () {
    $syncedAt = $this->ahora->subMinutes(40);

    expect($this->estado->decidir('2026-08-07', $syncedAt, '2026-08-07', 0, $this->ahora))
        ->toBe(EstadoDelTablero::SIN_FUNCIONES);

    expect($this->estado->esViejo($syncedAt, $this->ahora))->toBeTrue();
});

test('sin cabecera, esViejo es verdadero', function () {
    expect($this->estado->esViejo(null, $this->ahora))->toBeTrue();
});

test('el umbral sale del constructor y no está cableado', function () {
    $laxo = new EstadoDelTablero(60);

    expect($laxo->decidir('2026-08-07', $this->ahora->subMinutes(30), '2026-08-07', 11, $this->ahora))
        ->toBe(EstadoDelTablero::NORMAL);

    expect($laxo->esViejo($this->ahora->subMinutes(30), $this->ahora))->toBeFalse();
});

test('un synced_at en el futuro no se toma como viejo, ni con un desfase mayor que el umbral', function () {
    // Un reloj corrido en el servidor no debe disparar la banda de antigüedad.
    //
    // El desfase de 40 minutos es el que importa, y por eso no alcanza con uno
    // chico: `diffInMinutes()` sin el `false` devuelve el valor ABSOLUTO, así que
    // con +5 minutos da 5, que igual queda por debajo del umbral de 15 y el test
    // pasa con o sin el `false`. Recién con un desfase mayor que el umbral los dos
    // criterios se separan: con signo da -40 (no es viejo), sin signo da 40 (viejo),
    // y el tablero mostraría la banda de dato viejo sobre un dato recién traído.
    expect($this->estado->esViejo($this->ahora->addMinutes(40), $this->ahora))->toBeFalse();
    expect($this->estado->esViejo($this->ahora->addMinutes(5), $this->ahora))->toBeFalse();
});
