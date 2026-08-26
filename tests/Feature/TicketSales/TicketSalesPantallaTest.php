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

test('el panel izquierdo junta las funciones del domo y las ordena por hora', function () {
    // El show se llama «Marte» y no «San Cosmos» a propósito: el rótulo por
    // defecto del panel ES «San Cosmos», y un show con ese nombre haría que el
    // assertSee del rótulo pase incluso si el nombre se estuviera filtrando.
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 7]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Marte', 'hora' => '08:30', 'entradas_vendidas' => 30, 'categorias' => ['san-cosmos']]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Marte', 'hora' => '09:30', 'entradas_vendidas' => 12, 'categorias' => ['san-cosmos']]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    expect(array_column($respuesta->viewData('sanCosmos')['funciones'], 'hora'))
        ->toBe(['08:30', '09:30']);

    // El rótulo se ve; el nombre del show del domo, no. «Aves» queda a la derecha.
    $respuesta->assertSee('San Cosmos')->assertSee('08:30')
        ->assertDontSee('Marte')
        ->assertSee('Aves');
});

test('los demás shows van al panel de programación', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Aves', 'hora' => '10:00', 'entradas_vendidas' => 7]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Marte', 'hora' => '08:30', 'entradas_vendidas' => 30, 'categorias' => ['san-cosmos']]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    expect(array_column($respuesta->viewData('especiales'), 'show'))->toBe(['Aves']);

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
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Marte', 'hora' => '08:30', 'entradas_vendidas' => 30, 'categorias' => ['san-cosmos']]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Marte', 'hora' => '09:30', 'entradas_vendidas' => 25, 'categorias' => ['san-cosmos']]);
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
    expect($respuesta->viewData('sanCosmos')['funciones'])->toBe([]);
    expect($respuesta->viewData('especiales'))->toBe([]);
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
    // Los dos van al panel derecho: a la izquierda ya no hay nombre que cortar,
    // así que `nombreCorto()` solo vive del lado de los especiales.
    expect(array_column($respuesta->viewData('especiales'), 'show'))
        ->toBe(['Entrada al Gran Bioestanque', 'Entradas para las 4 funciones']);
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

/*
 * El caso del 2026-08-16, sembrado tal cual: «Exploradores de Exoplanetas» con
 * dos funciones programadas y una tercera fila huérfana —slot renombrado en
 * WordPress, cupos en null— que cae a la misma hora que una de ellas.
 */
test('dos filas del mismo producto a la misma hora se ven como una sola tarjeta', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['categorias' => ['san-cosmos'], 'producto_id' => 1, 'show_nombre' => 'Exoplanetas', 'slot' => 'Exoplanetas (15:30)', 'hora' => '15:30', 'entradas_vendidas' => 25, 'cupos_habilitados' => 5]);
    sembrarFuncionEnPantalla($this->hoy, ['categorias' => ['san-cosmos'], 'producto_id' => 1, 'show_nombre' => 'Exoplanetas', 'slot' => 'Exoplanetas (16:30)', 'hora' => '16:30', 'entradas_vendidas' => 2, 'cupos_habilitados' => 28]);
    sembrarFuncionEnPantalla($this->hoy, ['categorias' => ['san-cosmos'], 'producto_id' => 1, 'show_nombre' => 'Exoplanetas', 'slot' => 'Exoplanetas 3D (16:30)', 'hora' => '16:30', 'entradas_vendidas' => 20, 'cupos_habilitados' => null]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->assertSee('data-cifra="25"', false)
        ->assertSee('data-cifra="22"', false)
        // Las dos cifras partidas no pueden quedar en la pantalla.
        ->assertDontSee('data-cifra="02"', false)
        ->assertDontSee('data-cifra="20"', false);

    expect($respuesta->viewData('sanCosmos')['funciones'])->toBe([
        ['hora' => '15:30', 'entradas' => 25],
        ['hora' => '16:30', 'entradas' => 22],
    ]);
});

test('el tablero sigue mostrando las tres filas por separado', function () {
    // La contracara de la fusión, y por qué el tablero no se toca: es la única
    // vista donde se ve que hay una función huérfana. Fusionarla también acá
    // volvería invisible el problema del WordPress que la origina.
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Exoplanetas', 'slot' => 'Exoplanetas (15:30)', 'hora' => '15:30', 'entradas_vendidas' => 25, 'cupos_habilitados' => 5]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Exoplanetas', 'slot' => 'Exoplanetas (16:30)', 'hora' => '16:30', 'entradas_vendidas' => 2, 'cupos_habilitados' => 28]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Exoplanetas', 'slot' => 'Exoplanetas 3D (16:30)', 'hora' => '16:30', 'entradas_vendidas' => 20, 'cupos_habilitados' => null]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Exoplanetas (16:30)')
        ->assertSee('Exoplanetas 3D (16:30)')
        // El «—» que delata la función huérfana sigue ahí.
        ->assertSee('data-cupos-vacio="1"', false)
        // Y las cifras siguen separadas: 22 es de la pantalla, no de acá.
        ->assertDontSee('>22<', false);
});

test('el total del día no cambia al fusionar', function () {
    // La fusión es de presentación. Si tocara los totales, la tarjeta de
    // «Entradas vendidas» del tablero y la pantalla se contradirían.
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['categorias' => ['san-cosmos'], 'producto_id' => 1, 'hora' => '16:30', 'entradas_vendidas' => 2]);
    sembrarFuncionEnPantalla($this->hoy, ['categorias' => ['san-cosmos'], 'producto_id' => 1, 'hora' => '16:30', 'entradas_vendidas' => 20]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    expect($respuesta->viewData('totalEntradas'))->toBe(22);
    // El panel izquierdo no lleva total propio: se suma lo que muestran sus
    // tarjetas, que es justo lo que el invariante quiere comparar.
    expect(array_sum(array_column($respuesta->viewData('sanCosmos')['funciones'], 'entradas')))->toBe(22);
});

/**
 * El criterio, escrito directo en `core_config`. La página que lo edita se
 * prueba aparte: acá lo que importa es que la pantalla lo lea.
 */
function guardarCriterioEnConfig(array $valor): void
{
    \Illuminate\Support\Facades\DB::table('core_config')->updateOrInsert(
        ['code' => \CarlVallory\KrayinTicketSales\Support\CriterioDeSanCosmos::CLAVE],
        ['value' => json_encode($valor), 'updated_at' => now()]
    );
}

test('la pantalla usa el criterio guardado en core_config', function () {
    guardarCriterioEnConfig(['titulo' => 'Domo MuCi', 'categorias' => ['solo-esta']]);

    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Marte',
        'hora' => '15:30', 'entradas_vendidas' => 9, 'categorias' => ['solo-esta']]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Taller de robots',
        'hora' => '16:00', 'entradas_vendidas' => 4, 'categorias' => ['san-cosmos']]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    // `san-cosmos` ya no cuenta: manda lo guardado, no lo sembrado por la migración.
    expect($respuesta->viewData('rotuloSanCosmos'))->toBe('Domo MuCi');
    expect(array_column($respuesta->viewData('especiales'), 'show'))->toBe(['Taller de robots']);
    expect($respuesta->viewData('sanCosmos')['funciones'])->toBe([['hora' => '15:30', 'entradas' => 9]]);

    $respuesta->assertDontSee('Marte');
});

test('el panel izquierdo muestra los horarios y las ventas del domo, y ningún nombre', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Experiencia adaptada',
        'hora' => '15:30', 'entradas_vendidas' => 25, 'categorias' => ['san-cosmos']]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Misterios de tu Cerebro',
        'hora' => '17:00', 'entradas_vendidas' => 8, 'categorias' => ['san-cosmos']]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    // Los horarios y las ventas, sí.
    $respuesta->assertSee('15:30')->assertSee('17:00')
        ->assertSee('data-cifra="25"', false)
        ->assertSee('data-cifra="08"', false);

    // Los nombres, no. Es el pedido.
    $respuesta->assertDontSee('Experiencia adaptada')->assertDontSee('Misterios de tu Cerebro');
});

test('dos shows del domo a la misma hora se ven como una sola tarjeta', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Marte',
        'slot' => 'Domo A', 'hora' => '16:30', 'entradas_vendidas' => 2, 'categorias' => ['san-cosmos']]);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Historias Estelares',
        'slot' => 'Domo B', 'hora' => '16:30', 'entradas_vendidas' => 20, 'categorias' => ['san-cosmos']]);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-cifra="22"');
    expect(substr_count($html, '16:30'))->toBe(1);
});

test('un día sin domo deja el panel izquierdo con su cartel y el 60/40 intacto', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Taller de robots',
        'hora' => '16:00', 'entradas_vendidas' => 4, 'categorias' => ['talleres']]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    expect($respuesta->getContent())->toContain('data-sin-domo="1"');
    $respuesta->assertSee('Taller de robots')
        // El cartel de "no hay ninguna función" es otro estado y no tiene que salir.
        ->assertDontSee('No hay funciones programadas');
});

test('un día de solo domo dice que solo hay funciones del domo', function () {
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Marte',
        'hora' => '15:30', 'entradas_vendidas' => 25, 'categorias' => ['san-cosmos']]);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-solo-domo="1"');
    expect($html)->not->toContain('data-sin-domo="1"');
});

test('un día sin ninguna función muestra el cartel a pantalla completa', function () {
    sembrarSyncEnPantalla($this->hoy);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('No hay funciones programadas');
    expect($html)->not->toContain('data-sin-domo="1"');
});

test('el rótulo del panel izquierdo sale del config, no del código', function () {
    guardarCriterioEnConfig(['titulo' => 'Domo MuCi', 'categorias' => ['san-cosmos']]);

    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Marte',
        'hora' => '15:30', 'entradas_vendidas' => 25, 'categorias' => ['san-cosmos']]);

    // La actividad especial no es de adorno: sin ella el panel derecho muestra
    // «Hoy solo hay funciones de Domo MuCi», el rótulo aparece por ahí, y un
    // `assertSee` pasaría con el título cableado. Lo encontró una mutación.
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 2, 'show_nombre' => 'Taller',
        'hora' => '16:00', 'entradas_vendidas' => 3, 'categorias' => ['talleres']]);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('<h1 class="panel__titulo">Domo MuCi</h1>');
    expect($html)->not->toContain('data-solo-domo');
});

test('con las categorías en null todo cae a la derecha, como antes del cambio', function () {
    // El estado real entre el deploy del CRM y el del servicio.
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Marte',
        'hora' => '15:30', 'entradas_vendidas' => 25, 'categorias' => null]);

    $respuesta = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.pantalla'))
        ->assertOk();

    $respuesta->assertSee('Marte');
    expect($respuesta->getContent())->toContain('data-sin-domo="1"');
});

test('el tablero de admin sigue mostrando los nombres del domo', function () {
    // No es olvido: es la única vista donde se ve una función huérfana, y
    // esconder el nombre ahí volvería invisible el problema del slot renombrado.
    sembrarSyncEnPantalla($this->hoy);
    sembrarFuncionEnPantalla($this->hoy, ['producto_id' => 1, 'show_nombre' => 'Experiencia adaptada',
        'hora' => '15:30', 'entradas_vendidas' => 25, 'categorias' => ['san-cosmos']]);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->assertSee('Experiencia adaptada');
});
