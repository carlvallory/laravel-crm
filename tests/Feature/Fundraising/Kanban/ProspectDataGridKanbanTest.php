<?php

use CarlVallory\KrayinFundraising\DataGrids\ProspectDataGrid;
use CarlVallory\KrayinFundraising\Models\KanbanColumn;
use CarlVallory\KrayinFundraising\Services\KanbanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(\Webkul\User\Models\User::find(1), 'user');
    DB::table('lead_pipeline_stages')->updateOrInsert(['id' => 5], [
        'code' => 'won', 'name' => 'Won', 'lead_pipeline_id' => 1, 'sort_order' => 5,
    ]);
    DB::table('persons')->insert([
        'id' => 990, 'name' => 'Instituto A', 'organization_id' => null,
        'emails' => json_encode([]), 'contact_numbers' => json_encode([]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('leads')->insert([
        'title' => 'A1', 'person_id' => 990, 'lead_value' => 0, 'net_value' => 7_000_000, 'total_usd' => 1000,
        'status' => 1, 'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => 5,
        'closed_at' => '2026-01-04 09:00:00', 'created_at' => '2026-01-04 09:00:00', 'updated_at' => now(),
    ]);
});

it('el grid de personas incluye la columna Kanban con el estado actual', function () {
    request()->merge(['ver' => 'personas']);
    $column = KanbanColumn::orderBy('position')->first();
    app(KanbanService::class)->addCard(990, $column->id);

    $response = app(ProspectDataGrid::class)->process();
    $data = json_decode($response->getContent(), true);

    $columnIndexes = collect($data['columns'])->pluck('index');
    expect($columnIndexes)->toContain('kanban_column_name');

    $record = collect($data['records'])->firstWhere('name', 'Instituto A');
    expect($record['kanban_column_name'])->toContain($column->name);
});

it('el grid de organizaciones NO incluye la columna Kanban', function () {
    request()->merge(['ver' => 'organizaciones']);

    $response = app(ProspectDataGrid::class)->process();
    $data = json_decode($response->getContent(), true);

    $columnIndexes = collect($data['columns'])->pluck('index');
    expect($columnIndexes)->not->toContain('kanban_column_name');
});
