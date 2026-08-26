<?php

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Models\TicketSalesSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config([
        'ticket-sales.service.url'            => 'http://127.0.0.1:8081',
        'ticket-sales.service.token'          => 'un-token',
        'ticket-sales.service.retry_delay_ms' => 0,
        'ticket-sales.retention_days'         => 7,
    ]);
});

function cuerpoDelServicio(array $funciones = [], array $avisos = [], string $fecha = '2026-08-07'): array
{
    return [
        'fecha'       => $fecha,
        'generado_en' => $fecha . 'T17:30:00-03:00',
        'avisos'      => $avisos,
        'funciones'   => $funciones,
    ];
}

function unaFuncion(array $sobre = []): array
{
    return array_merge([
        'producto_id'          => 192637,
        'show'                 => 'Entrada Bioestanque',
        'slot'                 => 'BioEstanque (16:00) (17:00)',
        'hora'                 => '17:00',
        'entradas_vendidas'    => 2,
        'entradas_reagendadas' => 0,
        'cupos_habilitados'    => 18,
        'recaudacion_neta'     => 63636,
        'recaudacion_bruta'    => 70000,
    ], $sobre);
}

test('escribe las funciones y la cabecera del día', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([
        unaFuncion(),
        unaFuncion(['slot' => 'BioEstanque (18:00) (19:00)', 'hora' => '19:00', 'entradas_vendidas' => 0, 'recaudacion_neta' => 0, 'recaudacion_bruta' => 0]),
    ]), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->count())->toBe(2);
    expect(TicketSalesSync::where('fecha', '2026-08-07')->first())->not->toBeNull();
});

test('cada campo del contrato aterriza en su columna y no en la de al lado', function () {
    // Los nueve valores son distintos entre sí a propósito: así ningún par de
    // campos se puede confundir sin que un assert lo vea. Sin este test, siete de
    // los nueve mapeos de `escribir()` se pueden intercambiar y la suite queda
    // verde — incluida `recaudacion_neta` <- `recaudacion_bruta`, que mostraría
    // plata equivocada en el tablero sin que nada se queje. Es exactamente el bug
    // que el plan del servicio se comió: un nombre de campo que no coincidía y no
    // falló, calló.
    Http::fake(['*' => Http::response(cuerpoDelServicio([unaFuncion([
        'entradas_reagendadas' => 5,
    ])]), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    $fila = TicketSalesSnapshot::where('fecha', '2026-08-07')->sole();

    expect($fila->producto_id)->toBe(192637);
    // `show` en el contrato, `show_nombre` en la tabla: SHOW es reservada en MySQL.
    expect($fila->show_nombre)->toBe('Entrada Bioestanque');
    expect($fila->slot)->toBe('BioEstanque (16:00) (17:00)');
    expect($fila->hora)->toBe('17:00');
    expect($fila->entradas_vendidas)->toBe(2);
    expect($fila->entradas_reagendadas)->toBe(5);
    expect($fila->cupos_habilitados)->toBe(18);
    expect($fila->recaudacion_neta)->toBe(63636);
    expect($fila->recaudacion_bruta)->toBe(70000);
});

test('la función sin ventas queda en cero, no desaparece', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([
        unaFuncion(['entradas_vendidas' => 0, 'recaudacion_neta' => 0, 'recaudacion_bruta' => 0]),
    ]), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    $fila = TicketSalesSnapshot::where('producto_id', 192637)->first();

    expect($fila)->not->toBeNull();
    expect($fila->entradas_vendidas)->toBe(0);
    expect($fila->cupos_habilitados)->toBe(18);
});

test('sin --fecha usa el día de negocio en la zona del museo', function () {
    $hoy = app(\CarlVallory\KrayinTicketSales\Support\BusinessDay::class)->todayString();

    Http::fake(['*' => Http::response(cuerpoDelServicio([unaFuncion()], [], $hoy), 200)]);

    $this->artisan('ticket-sales:sync')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), "fecha={$hoy}"));
    expect(TicketSalesSync::where('fecha', $hoy)->exists())->toBeTrue();
});

test('correr dos veces reemplaza en vez de duplicar', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([unaFuncion()]), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();
    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->count())->toBe(1);
    expect(TicketSalesSync::where('fecha', '2026-08-07')->count())->toBe(1);
});

// Los dos tests de abajo hacen dos syncs seguidos, y por eso van con UNA sola
// `Http::fake` con secuencia: llamar `Http::fake` dos veces NO reemplaza el stub
// anterior —los acumula y gana el primero que matchea—, así que el segundo sync
// recibiría otra vez el 200 bueno y el test no probaría nada.
test('un 503 falla el comando y deja el snapshot anterior intacto', function () {
    Http::fake(['*' => Http::sequence()
        ->push(cuerpoDelServicio([unaFuncion()]), 200)
        // Dos veces el 503: el cliente reintenta una vez antes de rendirse.
        ->push(['error' => 'origen_no_disponible', 'mensaje' => 'x'], 503)
        ->push(['error' => 'origen_no_disponible', 'mensaje' => 'x'], 503),
    ]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();
    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertFailed();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->count())->toBe(1);
    expect(TicketSalesSync::where('fecha', '2026-08-07')->first()->avisos)->toBe([]);
});

test('una respuesta malformada deja el snapshot anterior intacto', function () {
    Http::fake(['*' => Http::sequence()
        ->push(cuerpoDelServicio([unaFuncion()]), 200)
        // Sin la clave `funciones`: el cliente la rechaza y el comando no debe
        // escribir. Un 200 no se reintenta, así que va una sola vez.
        ->push(['fecha' => '2026-08-07', 'generado_en' => 'x', 'avisos' => []], 200),
    ]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();
    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertFailed();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->count())->toBe(1);
});

test('un día sin funciones escribe la cabecera igual', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([]), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->count())->toBe(0);
    expect(TicketSalesSync::where('fecha', '2026-08-07')->exists())->toBeTrue();
});

test('los avisos se guardan y se loguean como warning', function () {
    Log::spy();

    Http::fake(['*' => Http::response(cuerpoDelServicio(
        [unaFuncion()],
        [['tipo' => 'estado_desconocido', 'detalle' => 'wc-nuevo']]
    ), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSync::where('fecha', '2026-08-07')->first()->avisos)->toHaveCount(1);
    Log::shouldHaveReceived('warning')->atLeast()->once();
});

test('un aviso con un tipo desconocido se guarda sin romper el sync', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio(
        [unaFuncion()],
        [['tipo' => 'un_codigo_del_futuro', 'detalle' => 'todavía no existe']]
    ), 200)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSync::where('fecha', '2026-08-07')->first()->avisos[0]['tipo'])
        ->toBe('un_codigo_del_futuro');
});

test('un 401 se loguea como error, no como warning', function () {
    Log::spy();

    Http::fake(['*' => Http::response(['error' => 'no_autorizado', 'mensaje' => 'x'], 401)]);

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertFailed();

    Log::shouldHaveReceived('error')->atLeast()->once();
});

test('la purga borra lo más viejo que la retención y respeta el resto', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([unaFuncion()]), 200)]);

    // Dos días viejos: uno adentro de la ventana de 7 días, otro afuera.
    foreach (['2026-08-05', '2026-07-20'] as $fechaVieja) {
        TicketSalesSnapshot::create([
            'fecha' => $fechaVieja, 'producto_id' => 1, 'show_nombre' => 'X', 'slot' => 'Y',
            'hora' => null, 'entradas_vendidas' => 0, 'entradas_reagendadas' => 0,
            'cupos_habilitados' => null, 'recaudacion_neta' => 0, 'recaudacion_bruta' => 0,
        ]);
        TicketSalesSync::create([
            'fecha' => $fechaVieja, 'generado_en' => now(), 'avisos' => [], 'synced_at' => now(),
        ]);
    }

    $this->artisan('ticket-sales:sync', ['--fecha' => '2026-08-07'])->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-07-20')->exists())->toBeFalse();
    expect(TicketSalesSync::where('fecha', '2026-07-20')->exists())->toBeFalse();
    expect(TicketSalesSnapshot::where('fecha', '2026-08-05')->exists())->toBeTrue();
    expect(TicketSalesSync::where('fecha', '2026-08-05')->exists())->toBeTrue();
});

test('las categorías de cada función aterrizan en su columna', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([
        unaFuncion(['categorias' => ['san-cosmos', 'eventos']]),
    ]), 200)]);

    $this->artisan('ticket-sales:sync --fecha=2026-08-07')->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->first()->categorias)
        ->toBe(['san-cosmos', 'eventos']);
});

test('una función sin el campo queda con la columna en null', function () {
    // El servicio viejo. El sync no tiene que inventar una lista vacía: null
    // es "no sé" y es la verdad de ese momento.
    Http::fake(['*' => Http::response(cuerpoDelServicio([unaFuncion()]), 200)]);

    $this->artisan('ticket-sales:sync --fecha=2026-08-07')->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->first()->categorias)->toBeNull();
});

test('una función con lista vacía queda con lista vacía, no con null', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([
        unaFuncion(['categorias' => []]),
    ]), 200)]);

    $this->artisan('ticket-sales:sync --fecha=2026-08-07')->assertSuccessful();

    expect(TicketSalesSnapshot::where('fecha', '2026-08-07')->first()->categorias)->toBe([]);
});

test('cada función guarda sus propias categorías, no las de la anterior', function () {
    Http::fake(['*' => Http::response(cuerpoDelServicio([
        unaFuncion(['producto_id' => 1, 'slot' => 'A', 'hora' => '10:00', 'categorias' => ['san-cosmos']]),
        unaFuncion(['producto_id' => 2, 'slot' => 'B', 'hora' => '11:00', 'categorias' => ['talleres']]),
    ]), 200)]);

    $this->artisan('ticket-sales:sync --fecha=2026-08-07')->assertSuccessful();

    $filas = TicketSalesSnapshot::where('fecha', '2026-08-07')->orderBy('producto_id')->get();

    expect($filas[0]->categorias)->toBe(['san-cosmos']);
    expect($filas[1]->categorias)->toBe(['talleres']);
});
