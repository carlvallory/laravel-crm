<?php

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Models\TicketSalesSync;
use CarlVallory\KrayinTicketSales\Support\BusinessDay;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->hoy = app(BusinessDay::class)->todayString();

    // Todo relativo a hoy: con fechas fijas, estos tests se pudren solos cuando
    // el día de verdad sale de la ventana de retención.
    $this->ayer     = \Carbon\Carbon::parse($this->hoy)->subDay()->format('Y-m-d');
    $this->haceTres = \Carbon\Carbon::parse($this->hoy)->subDays(3)->format('Y-m-d');
    $this->minima   = \Carbon\Carbon::parse($this->hoy)->subDays(7)->format('Y-m-d');
    $this->vieja    = \Carbon\Carbon::parse($this->hoy)->subDays(8)->format('Y-m-d');
    $this->manana   = \Carbon\Carbon::parse($this->hoy)->addDay()->format('Y-m-d');

    $this->admin = getDefaultAdmin();

    if (! $this->admin) {
        $this->markTestSkipped('No hay usuarios en la base local.');
    }
});

/** Helpers propios: si este archivo se corre solo, los de los otros no existen. */
function sembrarFuncionEnFecha(string $fecha, array $sobre = []): TicketSalesSnapshot
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

function sembrarSyncEnFecha(string $fecha, ?\Carbon\CarbonInterface $syncedAt = null): TicketSalesSync
{
    return TicketSalesSync::create([
        'fecha'       => $fecha,
        'generado_en' => $syncedAt ?? now(),
        'avisos'      => [],
        'synced_at'   => $syncedAt ?? now(),
    ]);
}

test('sin parámetro el tablero muestra hoy', function () {
    sembrarSyncEnFecha($this->hoy);
    sembrarFuncionEnFecha($this->hoy, ['show_nombre' => 'Show de hoy']);
    sembrarSyncEnFecha($this->haceTres);
    sembrarFuncionEnFecha($this->haceTres, ['show_nombre' => 'Show de hace tres']);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Show de hoy')
        ->assertDontSee('Show de hace tres');

    expect($respuesta->viewData('fecha'))->toBe($this->hoy);
    expect($respuesta->viewData('esHoy'))->toBeTrue();
});

test('con fecha el tablero muestra esa fecha y no la de hoy', function () {
    sembrarSyncEnFecha($this->hoy);
    sembrarFuncionEnFecha($this->hoy, ['show_nombre' => 'Show de hoy']);
    sembrarSyncEnFecha($this->haceTres);
    sembrarFuncionEnFecha($this->haceTres, ['show_nombre' => 'Show de hace tres']);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index', ['fecha' => $this->haceTres]))
        ->assertOk()
        ->assertSee('Show de hace tres')
        ->assertDontSee('Show de hoy');

    expect($respuesta->viewData('fecha'))->toBe($this->haceTres);
    expect($respuesta->viewData('esHoy'))->toBeFalse();
});

/*
 * La guarda de OTRO_DIA es lo más caro que puede romper este cambio: el selector
 * toca justamente de dónde sale la cabecera. Los tres tests que siguen la fijan
 * desde los tres lados por los que se puede caer.
 */
test('en hoy la guarda de sincronización rota sigue disparando', function () {
    // El viernes que el sync se rompe: la cabecera más nueva es de ayer. Si la
    // cabecera se buscara por fecha, acá saldría «nunca corrió» —falso— y el
    // mensaje mandaría a ejecutar el comando en vez de avisar de la falla.
    sembrarSyncEnFecha($this->ayer);
    sembrarFuncionEnFecha($this->ayer, ['show_nombre' => 'Show de ayer']);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('La sincronización viene fallando')
        ->assertDontSee('Todavía no hay datos')
        ->assertDontSee('Show de ayer');
});

test('pedir hoy a mano no apaga la guarda', function () {
    // El enlace de la pantalla podría venir con `?fecha=<hoy>` y ahí la guarda
    // se apagaría sin que nadie lo note. `esHoy()` compara la fecha, no si el
    // parámetro vino o no.
    sembrarSyncEnFecha($this->ayer);
    sembrarFuncionEnFecha($this->ayer);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index', ['fecha' => $this->hoy]))
        ->assertOk()
        ->assertSee('La sincronización viene fallando');
});

test('en una fecha elegida la guarda no dispara', function () {
    // La otra mitad: la guarda compara contra hoy, y en un día cerrado esa
    // comparación no dice nada. Sin este test, «disparar siempre» sobrevive.
    sembrarSyncEnFecha($this->haceTres);
    sembrarFuncionEnFecha($this->haceTres, ['show_nombre' => 'Show de hace tres']);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index', ['fecha' => $this->haceTres]))
        ->assertOk()
        ->assertSee('Show de hace tres')
        ->assertDontSee('La sincronización viene fallando');
});

test('una fecha sin cabecera dice que no hay datos, no que nunca corrió', function () {
    sembrarSyncEnFecha($this->hoy);
    sembrarFuncionEnFecha($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index', ['fecha' => $this->haceTres]))
        ->assertOk()
        ->assertSee('No hay datos guardados del')
        ->assertSee('ticket-sales:sync --fecha=' . $this->haceTres, false)
        ->assertDontSee('Todavía no hay datos');
});

test('una fecha con cabecera y sin funciones no se confunde con no tener datos', function () {
    // El par del de arriba. Los dos muestran cero filas y son cosas distintas:
    // un lunes que el museo cierra no es lo mismo que un día sin sincronizar.
    sembrarSyncEnFecha($this->haceTres);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index', ['fecha' => $this->haceTres]))
        ->assertOk()
        ->assertSee('No hay funciones programadas para el')
        ->assertDontSee('No hay datos guardados del');
});

test('una fecha fuera de la ventana explica la ventana', function () {
    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index', ['fecha' => $this->vieja]))
        ->assertOk()
        ->assertSee('No hay datos guardados del')
        ->assertSee('El snapshot conserva una semana')
        ->assertDontSee('ticket-sales:sync --fecha=', false);
});

test('una fecha futura dice que todavía no llegó', function () {
    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index', ['fecha' => $this->manana]))
        ->assertOk()
        ->assertSee('Esa fecha todavía no llegó')
        ->assertDontSee('El snapshot conserva una semana');
});

test('una fecha basura cae en hoy sin romper nada', function () {
    sembrarSyncEnFecha($this->hoy);
    sembrarFuncionEnFecha($this->hoy, ['show_nombre' => 'Show de hoy']);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index', ['fecha' => 'lunes pasado']))
        ->assertOk()
        ->assertSee('Show de hoy');

    expect($respuesta->viewData('fecha'))->toBe($this->hoy);
});

test('la banda de antigüedad no sale en una fecha elegida', function () {
    // El sync de un día cerrado siempre está viejo: se escribió ese día y nunca
    // más. Sin apagarla, toda fecha pasada saldría con la banda encendida.
    sembrarSyncEnFecha($this->haceTres, now()->subDays(3));
    sembrarFuncionEnFecha($this->haceTres);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index', ['fecha' => $this->haceTres]))
        ->assertOk()
        ->assertSee('data-historico="1"', false)
        ->assertDontSee('data-viejo=', false);
});

test('el picker viene con la ventana puesta', function () {
    sembrarSyncEnFecha($this->hoy);
    sembrarFuncionEnFecha($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('name="fecha"', false)
        ->assertSee('min="' . $this->minima . '"', false)
        ->assertSee('max="' . $this->hoy . '"', false);
});

test('ver en pantalla se lleva la fecha elegida', function () {
    sembrarSyncEnFecha($this->haceTres);
    sembrarFuncionEnFecha($this->haceTres);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index', ['fecha' => $this->haceTres]))
        ->assertOk()
        ->assertSee(route('krayin.ticket-sales.pantalla', ['fecha' => $this->haceTres]), false);
});

test('en hoy el enlace a la pantalla va pelado', function () {
    // Así la TV del hall arranca en hoy contra la ruta sin parámetro, y de paso
    // el enlace no queda clavado en la fecha del día que alguien lo copió.
    sembrarSyncEnFecha($this->hoy);
    sembrarFuncionEnFecha($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertDontSee('pantalla?fecha', false);
});

test('la pantalla muestra la fecha que le pasan', function () {
    sembrarSyncEnFecha($this->hoy);
    sembrarFuncionEnFecha($this->hoy, ['show_nombre' => 'Show de hoy']);
    sembrarSyncEnFecha($this->haceTres);
    sembrarFuncionEnFecha($this->haceTres, ['show_nombre' => 'Show de hace tres']);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla', ['fecha' => $this->haceTres]))
        ->assertOk()
        ->assertSee('Show de hace tres')
        ->assertDontSee('Show de hoy')
        ->assertSee('data-historico="1"', false);

    expect($respuesta->viewData('fecha'))->toBe($this->haceTres);
});

test('la pantalla sin parámetro muestra hoy', function () {
    // Es como se prende la TV del hall: ruta pelada, sin que nadie configure.
    sembrarSyncEnFecha($this->hoy);
    sembrarFuncionEnFecha($this->hoy, ['show_nombre' => 'Show de hoy']);
    sembrarSyncEnFecha($this->haceTres);
    sembrarFuncionEnFecha($this->haceTres, ['show_nombre' => 'Show de hace tres']);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('Show de hoy')
        ->assertDontSee('Show de hace tres')
        ->assertSee('data-viejo=', false);
});

test('una fecha cerrada no se recarga sola, ni en el tablero ni en la pantalla', function () {
    sembrarSyncEnFecha($this->haceTres);
    sembrarFuncionEnFecha($this->haceTres);

    foreach (['krayin.ticket-sales.index', 'krayin.ticket-sales.pantalla'] as $ruta) {
        $this->actingAs($this->admin, 'user')
            ->get(route($ruta, ['fecha' => $this->haceTres]))
            ->assertOk()
            ->assertDontSee('http-equiv="refresh"', false);
    }
});

test('hoy sí se recarga sola en las dos vistas', function () {
    // La otra mitad: sin esto, sacar el meta refresh de una vez y para siempre
    // pasaría los tests y dejaría la TV congelada en el primer dato del día.
    sembrarSyncEnFecha($this->hoy);
    sembrarFuncionEnFecha($this->hoy);

    foreach (['krayin.ticket-sales.index', 'krayin.ticket-sales.pantalla'] as $ruta) {
        $this->actingAs($this->admin, 'user')
            ->get(route($ruta))
            ->assertOk()
            ->assertSee('http-equiv="refresh"', false);
    }
});

test('la pantalla en una fecha sin datos no dice que no hubo funciones', function () {
    // En una TV, «no hubo funciones» sobre un día que en realidad no se guardó
    // es un cero que nadie va a cuestionar desde el otro lado del hall.
    sembrarSyncEnFecha($this->hoy);
    sembrarFuncionEnFecha($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla', ['fecha' => $this->haceTres]))
        ->assertOk()
        ->assertSee('No hay datos guardados')
        ->assertDontSee('No hay funciones programadas');
});
