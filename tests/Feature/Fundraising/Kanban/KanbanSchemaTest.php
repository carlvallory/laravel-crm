<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

it('crea las 4 tablas del kanban', function () {
    expect(Schema::hasTable('fundraising_kanban_columns'))->toBeTrue();
    expect(Schema::hasTable('fundraising_kanban_cards'))->toBeTrue();
    expect(Schema::hasTable('fundraising_card_notes'))->toBeTrue();
    expect(Schema::hasTable('fundraising_activities'))->toBeTrue();
});

it('siembra las 3 columnas iniciales con los colores de la paleta MuCi', function () {
    $columns = DB::table('fundraising_kanban_columns')->orderBy('position')->get();

    expect($columns->pluck('name')->all())
        ->toBe(['A contactar', 'Contactado positivo', 'Contactado negativo']);
    expect($columns->pluck('color')->all())
        ->toBe(['#F17DB1', '#00B26B', '#F37043']);
});

it('impone unicidad de person_id en las tarjetas', function () {
    $columnId = DB::table('fundraising_kanban_columns')->min('id');

    DB::table('fundraising_kanban_cards')->insert([
        'person_id' => 12345, 'column_id' => $columnId, 'position' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('fundraising_kanban_cards')->insert([
        'person_id' => 12345, 'column_id' => $columnId, 'position' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
