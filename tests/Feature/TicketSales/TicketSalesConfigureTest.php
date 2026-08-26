<?php

use CarlVallory\KrayinTicketSales\Models\TicketSalesSnapshot;
use CarlVallory\KrayinTicketSales\Support\CriterioDeSanCosmos;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Mismo arranque que TicketSalesPantallaTest: sin usuarios en la base local
    // no hay forma de entrar a una ruta con middleware `user`.
    $this->admin = getDefaultAdmin();

    if (! $this->admin) {
        $this->markTestSkipped('No hay usuarios en la base local.');
    }
});

/** Helpers propios: los del test de pantalla son suyos, por si este corre solo. */
function guardarCriterioDesdeConfigure(array $valor): void
{
    DB::table('core_config')->updateOrInsert(
        ['code' => CriterioDeSanCosmos::CLAVE],
        ['value' => json_encode($valor), 'updated_at' => now()]
    );
}

function sembrarFuncionParaCandidatas(int $productoId, ?array $categorias): void
{
    TicketSalesSnapshot::create([
        'fecha'       => now()->format('Y-m-d'),
        'producto_id' => $productoId,
        'show_nombre' => "Show {$productoId}",
        'slot'        => "Slot {$productoId}",
        'hora'        => '15:30',
        'categorias'  => $categorias,
    ]);
}

test('un visitante sin sesión no puede ver la configuración', function () {
    $this->get(route('krayin.ticket-sales.configure'))->assertRedirect();
});

test('guarda las categorías elegidas y el rótulo', function () {
    $this->actingAs($this->admin, 'user')
        ->post(route('krayin.ticket-sales.configure.store'), [
            'titulo'     => 'Domo MuCi',
            'categorias' => ['san-cosmos', 'entrada-sancosmos'],
        ])
        ->assertRedirect();

    $criterio = CriterioDeSanCosmos::desdeConfig();

    expect($criterio->titulo())->toBe('Domo MuCi');
    expect($criterio->categorias())->toBe(['san-cosmos', 'entrada-sancosmos']);
});

test('el campo libre agrega una categoría que todavía no apareció', function () {
    $this->actingAs($this->admin, 'user')
        ->post(route('krayin.ticket-sales.configure.store'), [
            'titulo'          => 'San Cosmos',
            'categorias'      => ['san-cosmos'],
            'categoria_nueva' => '  Domo-Nuevo ',
        ])
        ->assertRedirect();

    expect(CriterioDeSanCosmos::desdeConfig()->categorias())
        ->toBe(['san-cosmos', 'domo-nuevo']);
});

test('guardar sin ninguna categoría deja la lista vacía, no lanza', function () {
    // Es una elección válida: apaga el panel del domo. Lo que no puede hacer es
    // romper la página.
    $this->actingAs($this->admin, 'user')
        ->post(route('krayin.ticket-sales.configure.store'), ['titulo' => 'San Cosmos'])
        ->assertRedirect();

    expect(CriterioDeSanCosmos::desdeConfig()->categorias())->toBe([]);
});

test('un título vacío guarda el rótulo por defecto', function () {
    $this->actingAs($this->admin, 'user')
        ->post(route('krayin.ticket-sales.configure.store'), [
            'titulo'     => '   ',
            'categorias' => ['san-cosmos'],
        ])
        ->assertRedirect();

    expect(CriterioDeSanCosmos::desdeConfig()->titulo())
        ->toBe(CriterioDeSanCosmos::TITULO_POR_DEFECTO);
});

test('un criterio que no entra en la columna se rechaza con mensaje, no se trunca', function () {
    // `core_config.value` es un VARCHAR(255). MySQL en modo no estricto
    // truncaría en silencio, y un criterio truncado reparte mal sin avisar.
    guardarCriterioDesdeConfigure(['titulo' => 'San Cosmos', 'categorias' => ['san-cosmos']]);

    $muchas = array_map(fn ($i) => "categoria-larguisima-numero-{$i}", range(1, 30));

    $this->actingAs($this->admin, 'user')
        ->post(route('krayin.ticket-sales.configure.store'), [
            'titulo'     => 'San Cosmos',
            'categorias' => $muchas,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    // Y lo que había antes queda intacto.
    expect(CriterioDeSanCosmos::desdeConfig()->categorias())->toBe(['san-cosmos']);
});

test('la página ofrece como candidatas las categorías vistas en la ventana de retención', function () {
    sembrarFuncionParaCandidatas(1, ['san-cosmos', 'eventos']);
    sembrarFuncionParaCandidatas(2, ['talleres']);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.configure'))
        ->assertOk()
        ->getContent();

    foreach (['san-cosmos', 'eventos', 'talleres'] as $slug) {
        expect($html)->toContain('value="' . $slug . '"');
    }
});

test('las filas con categorias en null no aportan candidatas ni rompen la página', function () {
    sembrarFuncionParaCandidatas(1, null);

    $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.configure'))
        ->assertOk();
});

test('las candidatas no se repiten aunque aparezcan en varias filas', function () {
    foreach ([1, 2, 3] as $id) {
        sembrarFuncionParaCandidatas($id, ['san-cosmos']);
    }

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.configure'))
        ->assertOk()
        ->getContent();

    expect(substr_count($html, 'value="san-cosmos"'))->toBe(1);
});

test('una categoría guardada que ya no tiene funciones recientes sigue apareciendo marcada', function () {
    // Sin esto, guardar dos veces le borra al usuario un criterio que sigue
    // siendo válido: el domo puede pasar semanas sin funciones de una categoría,
    // y entonces esa categoría no está entre las candidatas.
    guardarCriterioDesdeConfigure(['titulo' => 'San Cosmos', 'categorias' => ['vieja-pero-valida']]);

    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.configure'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('value="vieja-pero-valida"');
    expect($html)->toContain('sin funciones recientes');
});

test('el tablero enlaza a la configuración', function () {
    $html = $this->actingAs($this->admin, 'user')
        ->get(route('krayin.ticket-sales.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('krayin.ticket-sales.configure'));
});

test('lo que se guarda queda normalizado en la base, no solo al leerlo', function () {
    // `CriterioDeSanCosmos` normaliza al leer, así que un valor sucio se vería
    // bien de todos modos. Se normaliza también al guardar por dos motivos: el
    // tope de 255 se mide sobre el JSON guardado —los espacios cuentan— y un
    // valor viejo mal escrito se arregla con solo volver a guardar.
    $this->actingAs($this->admin, 'user')
        ->post(route('krayin.ticket-sales.configure.store'), [
            'titulo'     => 'San Cosmos',
            'categorias' => ['  SAN-Cosmos  '],
        ])
        ->assertRedirect();

    $crudo = DB::table('core_config')->where('code', CriterioDeSanCosmos::CLAVE)->value('value');

    expect($crudo)->toContain('"san-cosmos"');
    expect($crudo)->not->toContain('SAN-Cosmos');
});
