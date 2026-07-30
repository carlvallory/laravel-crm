<?php

use CarlVallory\KrayinOperations\Exports\RotationExport;
use CarlVallory\KrayinOperations\Models\ProductState;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

it('el export respeta la pestaña (papelera fuera de activos, presente en papelera)', function () {
    $pid = DB::table('products')->insertGetId([
        'sku' => 'EXP-PAPE', 'name' => 'ExportPapelera', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    ProductState::create(['product_id' => $pid, 'user_id' => 1, 'status' => 'papelera']);

    // collection() es la fuente de filas del XLSX; SKU = índice 1 de cada fila mapeada.
    $skusOf = fn (string $estado) => collect((new RotationExport(['estado' => $estado]))->collection())
        ->pluck(1)->all();

    expect($skusOf('activos'))->not->toContain('EXP-PAPE');
    expect($skusOf('papelera'))->toContain('EXP-PAPE');
});

it('los endpoints de export responden 200 con la pestaña activa', function () {
    $this->actingAs(User::find(1), 'user')
        ->get(route('krayin.operations.rotation.export.xlsx', ['estado' => 'papelera']))
        ->assertOk();

    $this->actingAs(User::find(1), 'user')
        ->get(route('krayin.operations.rotation.export.pdf', ['estado' => 'papelera']))
        ->assertOk();
});
