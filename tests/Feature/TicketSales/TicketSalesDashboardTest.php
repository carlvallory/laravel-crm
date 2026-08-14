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

function sembrarFuncion(string $fecha, array $sobre = []): TicketSalesSnapshot
{
    return TicketSalesSnapshot::create(array_merge([
        'fecha'                => $fecha,
        'producto_id'          => 192637,
        'show_nombre'          => 'Entrada Bioestanque',
        'slot'                 => 'BioEstanque (16:00) (17:00)',
        'hora'                 => '17:00',
        'entradas_vendidas'    => 2,
        'entradas_reagendadas' => 0,
        'cupos_habilitados'    => 18,
        'recaudacion_neta'     => 63636,
        'recaudacion_bruta'    => 70000,
    ], $sobre));
}

function sembrarSync(string $fecha, ?\Carbon\CarbonInterface $syncedAt = null): TicketSalesSync
{
    return TicketSalesSync::create([
        'fecha'       => $fecha,
        'generado_en' => $syncedAt ?? now(),
        'avisos'      => [],
        'synced_at'   => $syncedAt ?? now(),
    ]);
}

test('un visitante sin sesión es redirigido al login', function () {
    $this->get(route('krayin.ticket-sales.index'))->assertRedirect();
});

test('estado 1: snapshot fresco muestra las funciones del día', function () {
    sembrarSync($this->hoy);
    sembrarFuncion($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Entrada Bioestanque')
        ->assertSee('BioEstanque (16:00) (17:00)')
        ->assertDontSee('La sincronización viene fallando');
});

test('estado 2: snapshot viejo muestra las funciones y además la banda', function () {
    sembrarSync($this->hoy, now()->subMinutes(40));
    sembrarFuncion($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Entrada Bioestanque')
        ->assertSee('data-viejo="1"', false);
});

test('estado 1: el snapshot fresco NO trae la banda de antigüedad', function () {
    sembrarSync($this->hoy, now()->subMinutes(3));
    sembrarFuncion($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('data-viejo="0"', false)
        ->assertDontSee('data-viejo="1"', false);
});

test('estado 3: un snapshot de ayer NO muestra sus funciones', function () {
    $ayer = \Carbon\Carbon::parse($this->hoy)->subDay()->format('Y-m-d');

    sembrarSync($ayer, now()->subDay());
    sembrarFuncion($ayer, ['show_nombre' => 'Show de ayer']);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('La sincronización viene fallando')
        ->assertDontSee('Show de ayer');

    // Las dos guardas se fijan por separado, a propósito. El `assertDontSee` de
    // arriba solo prueba la de la VISTA (la rama OTRO_DIA no dibuja la tabla), y
    // pasa igual si el controlador filtra por la fecha del sync en vez de por hoy
    // —o sea, si carga las funciones de ayer y confía en que la vista las
    // esconda—. Ese día, cualquier reordenamiento de la vista las deja salir.
    // Esto fija que las filas del otro día ni siquiera llegan a la vista.
    expect($respuesta->viewData('funciones'))->toHaveCount(0);
});

test('estado 4: sin cabecera dice que el sync nunca corrió', function () {
    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Todavía no hay datos')
        ->assertSee('ticket-sales:sync');
});

test('estado 5: hoy sincronizado y sin funciones dice que no hay funciones', function () {
    sembrarSync($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('No hay funciones programadas para hoy')
        ->assertDontSee('Todavía no hay datos');
});

test('una función sin ventas se muestra en cero, no desaparece', function () {
    sembrarSync($this->hoy);
    sembrarFuncion($this->hoy);
    sembrarFuncion($this->hoy, [
        'show_nombre'       => 'Historias Estelares',
        'slot'              => 'Entrada general (08:30)',
        'hora'              => '08:30',
        'entradas_vendidas' => 0,
        'cupos_habilitados' => 0,
        'recaudacion_neta'  => 0,
        'recaudacion_bruta' => 0,
    ]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Historias Estelares');
});

test('las funciones salen ordenadas por hora, no por nombre de show', function () {
    // Los nombres van al revés que las horas a propósito: «Zeta» es la de las
    // 08:30 y «Alfa» la de las 19:00. Con nombres cuyo orden alfabético coincide
    // con el de las horas (como «Mañana» antes de «Tarde»), sacar el
    // `orderByRaw('hora IS NULL, hora')` deja el test verde, porque el
    // `orderBy('show_nombre')` que queda da el mismo resultado por casualidad.
    sembrarSync($this->hoy);
    sembrarFuncion($this->hoy, ['show_nombre' => 'Alfa', 'slot' => 'S1', 'hora' => '19:00']);
    sembrarFuncion($this->hoy, ['show_nombre' => 'Zeta', 'slot' => 'S2', 'hora' => '08:30']);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->getContent();

    expect(strpos($html, 'Zeta'))->toBeLessThan(strpos($html, 'Alfa'));
});

test('a igual hora, el desempate es por nombre de show', function () {
    // La otra mitad del criterio: el `orderBy('show_nombre')` no está de adorno.
    sembrarSync($this->hoy);
    sembrarFuncion($this->hoy, ['show_nombre' => 'Zeta', 'slot' => 'S1', 'hora' => '17:00']);
    sembrarFuncion($this->hoy, ['show_nombre' => 'Alfa', 'slot' => 'S2', 'hora' => '17:00']);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->getContent();

    expect(strpos($html, 'Alfa'))->toBeLessThan(strpos($html, 'Zeta'));
});

test('las funciones sin hora van al final, no al principio', function () {
    // La tercera parte del `orderByRaw`: el `hora IS NULL`. Sin él, MySQL pone
    // los NULL primero y una función sin horario encabezaría el tablero.
    sembrarSync($this->hoy);
    sembrarFuncion($this->hoy, ['show_nombre' => 'Sin horario', 'slot' => 'S1', 'hora' => null]);
    sembrarFuncion($this->hoy, ['show_nombre' => 'Con horario', 'slot' => 'S2', 'hora' => '08:30']);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->getContent();

    expect(strpos($html, 'Con horario'))->toBeLessThan(strpos($html, 'Sin horario'));
});

test('cupos_habilitados en null se muestra como raya, no como cero', function () {
    sembrarSync($this->hoy);
    sembrarFuncion($this->hoy, ['cupos_habilitados' => null]);

    // Se asserta el atributo y no la raya suelta: «—» también está en el párrafo
    // del pie de la vista, así que `assertSee('—')` pasa aunque la celda muestre 0.
    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('data-cupos-vacio="1"', false)
        ->assertSee('—');
});

test('cupos_habilitados en cero se muestra como cero, no como raya', function () {
    // La otra mitad de la distinción del §11: 0 es venta online cerrada y null es
    // función no programada. Si los dos se dibujaran igual, boletería no podría
    // saber si todavía se puede vender.
    sembrarSync($this->hoy);
    sembrarFuncion($this->hoy, ['cupos_habilitados' => 0]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertDontSee('data-cupos-vacio', false);
});

test('la vista no muestra los avisos: su público es quien mantiene el sistema', function () {
    TicketSalesSync::create([
        'fecha'       => $this->hoy,
        'generado_en' => now(),
        'avisos'      => [['tipo' => 'estado_desconocido', 'detalle' => 'wc-inventado']],
        'synced_at'   => now(),
    ]);
    sembrarFuncion($this->hoy);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertDontSee('wc-inventado')
        ->assertDontSee('estado_desconocido');
});
