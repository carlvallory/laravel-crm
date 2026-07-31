<?php

use CarlVallory\KrayinFundraising\DataGrids\ProspectDataGrid;
use CarlVallory\KrayinFundraising\Models\KanbanColumn;
use CarlVallory\KrayinFundraising\Services\KanbanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

function seedWonPerson(): void
{
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
}

function userWithPermissions(array $permissions): \Webkul\User\Models\User
{
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'Rol ' . implode('-', $permissions ?: ['none']),
        'permission_type' => 'custom',
        'permissions' => json_encode($permissions),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $userId = DB::table('users')->insertGetId([
        'name' => 'Tester', 'email' => 'tester' . $roleId . '@muci.org',
        'role_id' => $roleId, 'status' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return \Webkul\User\Models\User::find($userId);
}

function kanbanCellFor(int $personId): string
{
    $response = app(ProspectDataGrid::class)->process();
    $data = json_decode($response->getContent(), true);
    $record = collect($data['records'])->firstWhere('prospect_id', $personId);

    return $record['kanban_column_name'] ?? '';
}

beforeEach(function () {
    request()->merge(['ver' => 'personas']);
    seedWonPerson();
});

it('con permiso fundraising.kanban renderiza un select con las columnas del board', function () {
    $this->actingAs(userWithPermissions(['fundraising', 'fundraising.kanban']), 'user');
    $column = KanbanColumn::orderBy('position')->first();
    app(KanbanService::class)->addCard(990, $column->id);

    $cell = kanbanCellFor(990);

    expect($cell)->toContain('<select');
    expect($cell)->toContain('data-kanban-assign');
    expect($cell)->toContain('data-person-id="990"');
    // una opción por cada columna sembrada
    foreach (KanbanColumn::all() as $c) {
        expect($cell)->toContain('>' . e($c->name) . '</option>');
    }
    // la columna actual queda seleccionada
    expect($cell)->toMatch('/<option value="' . $column->id . '"[^>]*selected/');
    // opción de quitar (tiene tarjeta)
    expect($cell)->toContain('__remove__');
});

it('sin permiso fundraising.kanban cae al badge de solo lectura (sin select)', function () {
    $this->actingAs(userWithPermissions(['fundraising']), 'user');

    $cell = kanbanCellFor(990);

    expect($cell)->not->toContain('<select');
    expect($cell)->toContain('Sin asignar');
});
