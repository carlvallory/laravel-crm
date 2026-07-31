<?php

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

it('devuelve columnas con sus tarjetas y datos del contacto', function () {
    $column = KanbanColumn::orderBy('position')->first();
    app(KanbanService::class)->addCard(990, $column->id);

    $response = $this->getJson(route('krayin.fundraising.kanban.index'));

    $response->assertOk();
    $response->assertJsonStructure([
        'columns' => [
            ['id', 'name', 'position', 'color', 'cards' => [
                ['person_id', 'name', 'organization_name', 'total_pyg', 'last_purchase', 'notes_count', 'position'],
            ]],
        ],
    ]);

    $card = collect($response->json('columns'))
        ->firstWhere('id', $column->id)['cards'][0];

    expect($card['person_id'])->toBe(990);
    expect($card['name'])->toBe('Instituto A');
    expect((float) $card['total_pyg'])->toBe(7_000_000.0);
});
