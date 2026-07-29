<?php

use CarlVallory\KrayinFundraising\Models\KanbanColumn;
use CarlVallory\KrayinFundraising\Queries\ProspectQuery;
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

it('sin tarjeta: las columnas kanban vienen nulas', function () {
    $row = ProspectQuery::forPersons()->get()->keyBy('prospect_id')->get(990);

    expect($row->kanban_column_id)->toBeNull();
    expect($row->kanban_column_name)->toBeNull();
});

it('con tarjeta: refleja la columna actual', function () {
    $column = KanbanColumn::orderBy('position')->first();
    app(KanbanService::class)->addCard(990, $column->id);

    $row = ProspectQuery::forPersons()->get()->keyBy('prospect_id')->get(990);

    expect((int) $row->kanban_column_id)->toBe($column->id);
    expect($row->kanban_column_name)->toBe($column->name);
    expect($row->kanban_column_color)->toBe($column->color);
});
