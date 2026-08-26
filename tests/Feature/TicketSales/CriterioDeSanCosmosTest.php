<?php

use CarlVallory\KrayinTicketSales\Support\CriterioDeSanCosmos;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

test('la migración deja el criterio sembrado en core_config', function () {
    $fila = DB::table('core_config')
        ->where('code', 'krayin_ticket_sales.settings.san_cosmos')
        ->first();

    expect($fila)->not->toBeNull();

    $valor = json_decode($fila->value, true);

    expect($valor['titulo'])->toBe('San Cosmos');

    // El slug real de «Ticketera SC 2.0», verificado contra producción el
    // 2026-08-26. NO es `ticketera-2-0`: en WordPress renombrar un término no
    // le cambia el slug.
    expect($valor['categorias'])->toContain('san-cosmos');
});

function guardarCriterio(mixed $valor): void
{
    DB::table('core_config')->updateOrInsert(
        ['code' => CriterioDeSanCosmos::CLAVE],
        ['value' => is_string($valor) ? $valor : json_encode($valor), 'updated_at' => now()]
    );
}

test('lee las categorías y el título de core_config', function () {
    guardarCriterio(['titulo' => 'Domo MuCi', 'categorias' => ['san-cosmos']]);

    $criterio = CriterioDeSanCosmos::desdeConfig();

    expect($criterio->titulo())->toBe('Domo MuCi');
    expect($criterio->categorias())->toBe(['san-cosmos']);
});

test('normaliza los slugs guardados: recorta, baja a minúsculas y descarta vacíos', function () {
    // El campo libre de la página de configuración lo llena una persona.
    guardarCriterio(['titulo' => 'San Cosmos', 'categorias' => ['  San-Cosmos ', '', 'ENTRADA-SANCOSMOS', '   ']]);

    expect(CriterioDeSanCosmos::desdeConfig()->categorias())
        ->toBe(['san-cosmos', 'entrada-sancosmos']);
});

test('no repite un slug cargado dos veces', function () {
    guardarCriterio(['titulo' => 'San Cosmos', 'categorias' => ['san-cosmos', 'San-Cosmos']]);

    expect(CriterioDeSanCosmos::desdeConfig()->categorias())->toBe(['san-cosmos']);
});

test('sin fila en core_config devuelve lista vacía y el título por defecto', function () {
    DB::table('core_config')->where('code', CriterioDeSanCosmos::CLAVE)->delete();

    $criterio = CriterioDeSanCosmos::desdeConfig();

    expect($criterio->categorias())->toBe([]);
    expect($criterio->titulo())->toBe('San Cosmos');
});

test('un valor con forma inesperada no lanza: devuelve vacío y el título por defecto', function () {
    // La consecuencia es acotada y visible: nada coincide, todo cae al panel
    // derecho con su nombre, y la página de configuración muestra la lista
    // vacía. Quien valida de verdad es el POST de esa página.
    foreach (['no-es-json', '"un string"', '123', '{"categorias": "san-cosmos"}', '[]'] as $basura) {
        guardarCriterio($basura);

        $criterio = CriterioDeSanCosmos::desdeConfig();

        expect($criterio->categorias())->toBe([]);
        expect($criterio->titulo())->toBe('San Cosmos');
    }
});

test('un título vacío o en blanco cae al por defecto', function () {
    // Un panel sin rótulo en una TV es peor que un rótulo genérico.
    guardarCriterio(['titulo' => '   ', 'categorias' => ['san-cosmos']]);

    expect(CriterioDeSanCosmos::desdeConfig()->titulo())->toBe('San Cosmos');
});

test('los elementos que no son strings se descartan sin tirar el resto', function () {
    guardarCriterio(['titulo' => 'San Cosmos', 'categorias' => ['san-cosmos', 128, null, ['anidada']]]);

    expect(CriterioDeSanCosmos::desdeConfig()->categorias())->toBe(['san-cosmos']);
});
