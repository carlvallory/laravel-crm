<?php

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
