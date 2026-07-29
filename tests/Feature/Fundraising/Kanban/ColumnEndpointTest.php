<?php

use CarlVallory\KrayinFundraising\Models\KanbanColumn;
use CarlVallory\KrayinFundraising\Services\KanbanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
    DB::table('persons')->insert([
        'id' => 990, 'name' => 'Instituto A', 'organization_id' => null,
        'emails' => json_encode([]), 'contact_numbers' => json_encode([]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('crea una columna vía POST', function () {
    $response = $this->postJson(route('krayin.fundraising.kanban.columns.store'), [
        'name' => 'Seguimiento', 'color' => '#6950A1',
    ]);

    $response->assertOk();
    expect(KanbanColumn::where('name', 'Seguimiento')->exists())->toBeTrue();
});

it('rechaza crear columna sin nombre con 422', function () {
    $this->postJson(route('krayin.fundraising.kanban.columns.store'), ['name' => ''])
        ->assertStatus(422);
});

it('renombra una columna vía PATCH', function () {
    $column = KanbanColumn::orderBy('position')->first();

    $this->patchJson(route('krayin.fundraising.kanban.columns.update', $column->id), ['name' => 'Pendientes'])
        ->assertOk();

    expect($column->fresh()->name)->toBe('Pendientes');
});

it('devuelve 404 al actualizar una columna inexistente', function () {
    $this->patchJson(route('krayin.fundraising.kanban.columns.update', 999999), ['name' => 'X'])
        ->assertStatus(404);
});

it('borrar una columna con tarjetas SIN destino devuelve 422', function () {
    $column = KanbanColumn::orderBy('position')->first();
    app(KanbanService::class)->addCard(990, $column->id);

    $this->deleteJson(route('krayin.fundraising.kanban.columns.destroy', $column->id))
        ->assertStatus(422);
});

it('borrar una columna con tarjetas y destino mueve y elimina', function () {
    $cols = KanbanColumn::orderBy('position')->get();
    app(KanbanService::class)->addCard(990, $cols[0]->id);

    $this->deleteJson(route('krayin.fundraising.kanban.columns.destroy', $cols[0]->id), [
        'target_column_id' => $cols[1]->id,
    ])->assertOk();

    expect(KanbanColumn::find($cols[0]->id))->toBeNull();
});
