<?php

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Models\TicketSalesSync;
use CarlVallory\KrayinTicketSales\Support\BusinessDay;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->hoy = app(BusinessDay::class)->todayString();

    $this->admin = getDefaultAdmin();

    if (! $this->admin) {
        $this->markTestSkipped('No hay usuarios en la base local.');
    }
});

/**
 * Helpers propios y no los del test del tablero: si este archivo se corre solo,
 * los de allá no existen.
 */
function sembrarFuncionEnPantalla(string $fecha, array $sobre = []): TicketSalesSnapshot
{
    return TicketSalesSnapshot::create(array_merge([
        'fecha'                => $fecha,
        'producto_id'          => 192637,
        'show_nombre'          => 'Entrada Bioestanque',
        'slot'                 => 'BioEstanque (16:00)',
        'hora'                 => '16:00',
        'entradas_vendidas'    => 2,
        'entradas_reagendadas' => 0,
        'cupos_habilitados'    => 18,
        'recaudacion_neta'     => 63636,
        'recaudacion_bruta'    => 70000,
    ], $sobre));
}

function sembrarSyncEnPantalla(string $fecha, ?\Carbon\CarbonInterface $syncedAt = null): TicketSalesSync
{
    return TicketSalesSync::create([
        'fecha'       => $fecha,
        'generado_en' => $syncedAt ?? now(),
        'avisos'      => [],
        'synced_at'   => $syncedAt ?? now(),
    ]);
}

test('un visitante sin sesión es redirigido al login', function () {
    $this->get(route('krayin.ticket-sales.pantalla'))->assertRedirect();
});

test('la pantalla no trae el chrome del CRM y el tablero sí', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy);

    // Las dos mitades van juntas a propósito. El `assertDontSee` solo prueba
    // algo si el marcador existe de verdad en la otra vista; si no, pasaría
    // igual con un marcador inventado.
    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertSee('sidebar-collapsed', false);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('data-pantalla="1"', false)
        ->assertDontSee('sidebar-collapsed', false);
});

test('el tablero enlaza a la pantalla y la pantalla vuelve al tablero', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee(route('krayin.ticket-sales.pantalla'), false);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee(route('krayin.ticket-sales.index'), false);
});

test('el destacado es el show con más entradas y muestra sus horarios', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 7]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '08:30', 'entradas_vendidas' => 30]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '09:30', 'entradas_vendidas' => 12]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    expect($respuesta->viewData('destacado')['show'])->toBe('San Cosmos');
    expect(array_column($respuesta->viewData('destacado')['funciones'], 'hora'))
        ->toBe(['08:30', '09:30']);

    $respuesta->assertSee('San Cosmos')->assertSee('08:30');
});

test('los demás shows van al panel de programación', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 7]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '08:30', 'entradas_vendidas' => 30]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    expect(array_column($respuesta->viewData('resto'), 'show'))->toBe(['Aves']);

    $respuesta->assertSee('Programación')->assertSee('Aves');
});

test('las cifras del destacado van a dos dígitos', function () {
    // Con `assertSee('02')` este test pasaría siempre: la fecha del día trae
    // «2026», que contiene «02». De ahí el atributo.
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['hora' => '10:30', 'entradas_vendidas' => 2]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('data-cifra="02"', false);
});

test('una cifra de tres dígitos no se recorta', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['hora' => '10:30', 'entradas_vendidas' => 167]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('data-cifra="167"', false);
});

test('un show del panel derecho con varias funciones muestra sus horarios', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '08:30', 'entradas_vendidas' => 30]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '16:00', 'entradas_vendidas' => 12]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '17:00', 'entradas_vendidas' => 0]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('data-multihorario="1"', false)
        ->assertSee('17:00');
});

test('un show del panel derecho con una sola función muestra la cifra, no un horario', function () {
    // La otra mitad del criterio, y sin este test la mutación «multihorario
    // siempre» sobrevive: el test de arriba solo prueba que el atributo
    // aparezca, no que aparezca únicamente cuando el show tiene varias.
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '08:30', 'entradas_vendidas' => 30]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'San Cosmos', 'hora' => '09:30', 'entradas_vendidas' => 25]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '16:00', 'entradas_vendidas' => 12]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('data-cifra="12"', false)
        ->assertDontSee('data-multihorario', false);
});

test('el dato viejo muestra la banda de antigüedad', function () {
    sembrarSyncEnPantalla($this->hoy, now()->subMinutes(40));
    sembrarFuncionEnPantalla($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('data-viejo="1"', false);
});

test('el dato fresco no muestra la banda', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('data-viejo="0"', false)
        ->assertDontSee('data-viejo="1"', false);
});

test('un snapshot de otro día no muestra las funciones', function () {
    sembrarSyncEnPantalla('2026-01-01', now());
    sembrarFuncionEnPantalla('2026-01-01', ['show_nombre' => 'Show de ayer']);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertDontSee('Show de ayer');

    // Las dos guardas por separado: que la vista no lo dibuje es una, que las
    // filas no lleguen a la vista es otra.
    expect($respuesta->viewData('destacado'))->toBeNull();
});

test('si el sync nunca corrió lo dice', function () {
    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('Todavía no hay datos');
});

test('un día sin funciones se distingue de que el sync nunca corrió', function () {
    sembrarSyncEnPantalla($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('No hay funciones programadas para hoy')
        ->assertDontSee('Todavía no hay datos');
});

test('un nombre largo se corta en el destacado y en las tarjetas', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Entrada al Gran Bioestanque', 'hora' => '10:00', 'entradas_vendidas' => 30]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Entradas para las 4 funciones', 'hora' => '16:00', 'entradas_vendidas' => 7]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('Entrada al Gran Bioe...')
        ->assertSee('Entradas para las 4...')
        ->assertDontSee('Entrada al Gran Bioestanque')
        ->assertDontSee('Entradas para las 4 funciones');

    // El corte es de presentación: el dato que llega a la vista sigue entero,
    // y el desempate del destacado se sigue decidiendo con el nombre completo.
    expect($respuesta->viewData('destacado')['show'])->toBe('Entrada al Gran Bioestanque');
    expect(array_column($respuesta->viewData('resto'), 'show'))->toBe(['Entradas para las 4 funciones']);
});

test('un nombre que entra no se toca en la pantalla', function () {
    // La otra mitad, y el nombre es de 23 justos a propósito: con uno corto,
    // la mutación «cortar siempre a 20» sobreviviría sin que nadie la vea.
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Entradas al Bioestanque', 'hora' => '10:00', 'entradas_vendidas' => 30]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Entradas al Anfiteatro', 'hora' => '16:00', 'entradas_vendidas' => 7]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('Entradas al Bioestanque')
        ->assertSee('Entradas al Anfiteatro');
});

test('el tablero de admin no corta los nombres', function () {
    // El corte se pidió para la TV, donde el rótulo compite con la cifra. En la
    // tabla del CRM el nombre entero se lee bien y sirve para buscar.
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['show_nombre' => 'Entrada al Gran Bioestanque']);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Entrada al Gran Bioestanque');
});

test('trae Poppins y se recarga sola', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('family=Poppins', false)
        ->assertSee('http-equiv="refresh"', false);
});
