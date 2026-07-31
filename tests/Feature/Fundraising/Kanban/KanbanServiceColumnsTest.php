<?php

use CarlVallory\KrayinFundraising\Models\Activity;
use CarlVallory\KrayinFundraising\Models\KanbanCard;
use CarlVallory\KrayinFundraising\Models\KanbanColumn;
use CarlVallory\KrayinFundraising\Services\KanbanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
});

it('crea una columna y loguea column_created', function () {
    $column = app(KanbanService::class)->createColumn('Seguimiento', '#6950A1');

    expect($column->name)->toBe('Seguimiento');
    expect($column->color)->toBe('#6950A1');
    expect(Activity::where('event', 'column_created')->where('to_column_id', $column->id)->exists())->toBeTrue();
});

it('renombra una columna y loguea column_renamed', function () {
    $column = KanbanColumn::orderBy('position')->first();
    app(KanbanService::class)->updateColumn($column, ['name' => 'Pendientes']);

    expect($column->fresh()->name)->toBe('Pendientes');
    expect(Activity::where('event', 'column_renamed')->where('to_column_id', $column->id)->exists())->toBeTrue();
});

it('borrar una columna con tarjetas SIN destino lanza InvalidArgumentException', function () {
    $column = KanbanColumn::orderBy('position')->first();
    app(KanbanService::class)->addCard(990, $column->id);

    expect(fn () => app(KanbanService::class)->deleteColumn($column))
        ->toThrow(\InvalidArgumentException::class);
});

it('borrar una columna con tarjetas mueve al destino y elimina', function () {
    $cols = KanbanColumn::orderBy('position')->get();
    $origen = $cols[0];
    $destino = $cols[1];

    app(KanbanService::class)->addCard(990, $origen->id);
    app(KanbanService::class)->deleteColumn($origen, $destino->id);

    expect(KanbanColumn::find($origen->id))->toBeNull();
    expect(KanbanCard::where('person_id', 990)->first()->column_id)->toBe($destino->id);
    expect(Activity::where('event', 'column_deleted')->where('from_column_id', $origen->id)->exists())->toBeTrue();
});

it('borrar una columna vacía la elimina directo', function () {
    $column = app(KanbanService::class)->createColumn('Vacía');
    app(KanbanService::class)->deleteColumn($column);

    expect(KanbanColumn::find($column->id))->toBeNull();
});
